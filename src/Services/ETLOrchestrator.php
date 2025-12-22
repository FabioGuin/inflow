<?php

namespace InFlow\Services;

use InFlow\Commands\InFlowCommand;
use InFlow\Contracts\ReaderInterface;
use InFlow\Detectors\FormatDetector;
use InFlow\Enums\File\SourceType;
use InFlow\Enums\Flow\ErrorPolicy;
use InFlow\Executors\FlowExecutor;
use InFlow\Loaders\EloquentLoader;
use InFlow\Mappings\MappingBuilder;
use InFlow\Mappings\MappingSerializer;
use InFlow\Mappings\MappingValidator;
use InFlow\Presenters\Contracts\PresenterInterface;
use InFlow\Profilers\Profiler;
use InFlow\Readers\CsvReader;
use InFlow\Readers\ExcelReader;
use InFlow\Readers\JsonLinesReader;
use InFlow\Readers\XmlReader;
use InFlow\Services\Core\ConfigurationResolver;
use InFlow\Services\Core\FlowExecutionService;
use InFlow\Services\DataProcessing\SanitizationService;
use InFlow\Services\File\FileReaderService;
use InFlow\Services\File\FileWriterService;
use InFlow\Services\Formatter\FileInfoFormatter;
use InFlow\Services\Formatter\FlowRunFormatter;
use InFlow\Services\Formatter\FormatInfoFormatter;
use InFlow\Services\Formatter\MessageFormatter;
use InFlow\Services\Formatter\PreviewFormatter;
use InFlow\Services\Formatter\ProgressInfoFormatter;
use InFlow\Services\Formatter\QualityReportFormatter;
use InFlow\Services\Formatter\SchemaFormatter;
use InFlow\Services\Formatter\StepProgressFormatter;
use InFlow\Services\Formatter\StepSummaryFormatter;
use InFlow\Services\Loading\PivotSyncService;
use InFlow\Services\Mapping\MappingDependencyValidator;
use InFlow\Services\Mapping\MappingGenerationService;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\Data\SourceSchema;
use InFlow\ValueObjects\File\DetectedFormat;
use InFlow\ValueObjects\Flow\Flow;
use InFlow\ValueObjects\Flow\ProcessingContext;
use InFlow\ValueObjects\Mapping\MappingDefinition;

/**
 * Simplified ETL orchestrator that replaces the pipeline and pipes.
 *
 * Consolidates all ETL steps into a single linear flow:
 * 1. Load file
 * 2. Read content
 * 3. Sanitize (if needed)
 * 4. Detect format
 * 5. Create reader
 * 6. Profile data (if no mapping)
 * 7. Process mapping
 * 8. Execute flow
 * 9. Display results
 */
readonly class ETLOrchestrator
{
    public function __construct(
        private ConfigurationResolver $configResolver,
        private FileReaderService $fileReader,
        private FileWriterService $fileWriter,
        private SanitizationService $sanitizationService,
        private FormatDetector $formatDetector,
        private Profiler $profiler,
        private MappingBuilder $mappingBuilder,
        private MappingSerializer $mappingSerializer,
        private MappingGenerationService $mappingGenerationService,
        private FlowExecutionService $flowExecutionService,
        private EloquentLoader $eloquentLoader,
        private MappingValidator $mappingValidator,
        private MappingDependencyValidator $dependencyValidator,
        private PivotSyncService $pivotSyncService,
        private FormatInfoFormatter $formatInfoFormatter,
        private SchemaFormatter $schemaFormatter,
        private PreviewFormatter $previewFormatter,
        private QualityReportFormatter $qualityReportFormatter,
        private FlowRunFormatter $flowRunFormatter,
        private MessageFormatter $messageFormatter,
        private StepProgressFormatter $stepProgressFormatter,
        private FileInfoFormatter $fileInfoFormatter,
        private StepSummaryFormatter $stepSummaryFormatter,
        private ProgressInfoFormatter $progressInfoFormatter
    ) {}

    /**
     * Process the ETL flow from start to finish.
     *
     * @param  InFlowCommand  $command  The command instance for I/O
     * @param  ProcessingContext  $context  The initial processing context
     * @param  PresenterInterface  $presenter  The presenter for output
     * @return ProcessingContext The updated context with results
     */
    public function process(InFlowCommand $command, ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        // Step 1: Load file
        $context = $this->loadFile($command, $context, $presenter);
        if ($context->cancelled || $context->source === null) {
            return $context;
        }

        // Step 2: Read content
        $context = $this->readContent($command, $context, $presenter);
        if ($context->cancelled || $context->content === null) {
            return $context;
        }

        // Step 3: Sanitize (if needed)
        $context = $this->sanitize($command, $context, $presenter);
        if ($context->cancelled) {
            return $context;
        }

        // Step 4: Detect format
        $context = $this->detectFormat($command, $context, $presenter);
        if ($context->cancelled || $context->format === null) {
            return $context;
        }

        // Step 5: Create reader
        $context = $this->createReader($command, $context, $presenter);
        if ($context->cancelled || $context->reader === null) {
            return $context;
        }

        // Step 6: Profile data (if no mapping provided)
        $context = $this->profileData($command, $context, $presenter);

        // Step 7: Process mapping
        $context = $this->processMapping($command, $context, $presenter);
        if ($context->cancelled || $context->mappingDefinition === null) {
            return $context;
        }

        // Step 8: Execute flow
        $context = $this->executeFlow($command, $context, $presenter);

        return $context;
    }

    /**
     * Step 1: Load file and create FileSource.
     */
    private function loadFile(InFlowCommand $command, ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        $presenter->presentStepProgress($this->stepProgressFormatter->format(1, 8, 'Loading file...'));

        try {
            $source = FileSource::fromPath($context->filePath);
            $context = $context->withSource($source);

            $presenter->presentMessage($this->messageFormatter->success('File loaded successfully'));
            $presenter->presentFileInfo($this->fileInfoFormatter->format($source));

            if ($presenter->presentStepSummary($this->stepSummaryFormatter->format('File loaded', [
                'Name' => $source->getName(),
                'Size' => $this->fileWriter->formatSize($source->getSize()),
            ]))) {
                return $context;
            }

            return $context->withCancelled();
        } catch (\RuntimeException $e) {
            $presenter->presentMessage($this->messageFormatter->error('Failed to load file: '.$e->getMessage()));
            throw $e;
        }
    }

    /**
     * Step 2: Read file content.
     */
    private function readContent(InFlowCommand $command, ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        if ($context->source === null) {
            return $context;
        }

        $presenter->presentStepProgress($this->stepProgressFormatter->format(2, 8, 'Reading file content...'));

        $content = $this->readFileContent($command, $context->source);
        $lineCount = $this->countLines($content);

        $context = $context
            ->withContent($content)
            ->withLineCount($lineCount);

        $presenter->presentProgressInfo($this->progressInfoFormatter->format(
            'Content read',
            lines: $lineCount,
            bytes: strlen($content)
        ));

        if ($presenter->presentStepSummary($this->stepSummaryFormatter->format('Content read', [
            'Lines' => number_format($lineCount),
            'Bytes' => number_format(strlen($content)),
        ]))) {
            return $context;
        }

        return $context->withCancelled();
    }

    /**
     * Step 3: Sanitize content if needed.
     */
    private function sanitize(InFlowCommand $command, ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        if ($context->content === null) {
            return $context;
        }

        $shouldSanitize = $this->determineShouldSanitize($command, $context);

        if ($shouldSanitize) {
            $presenter->presentStepProgress($this->stepProgressFormatter->format(3, 8, 'Sanitizing content...'));
            $presenter->presentMessage($this->messageFormatter->info('Cleaning file: removing BOM, normalizing newlines, removing control characters.'));

            $lineCount = $context->lineCount ?? 0;
            $sanitizerConfig = $this->getSanitizerConfig($command);
            [$sanitized] = $this->sanitizationService->sanitize($context->content, $sanitizerConfig);
            $newLineCount = $this->countLines($sanitized);

            if ($newLineCount !== $lineCount) {
                $presenter->presentMessage($this->messageFormatter->warning("Line count changed: {$lineCount} → {$newLineCount} (normalization effect)"));
            }

            $context = $context
                ->withContent($sanitized)
                ->withLineCount($newLineCount)
                ->withShouldSanitize(true);

            $presenter->presentMessage($this->messageFormatter->success('Sanitization completed'));

            if ($presenter->presentStepSummary($this->stepSummaryFormatter->format('Sanitization', [
                'Lines' => (string) $newLineCount,
                'Status' => 'cleaned',
            ]))) {
                return $context;
            }

            return $context->withCancelled();
        }

        $presenter->presentMessage($this->messageFormatter->warning('Sanitization skipped'));

        return $context->withShouldSanitize(false);
    }

    /**
     * Step 4: Detect file format.
     */
    private function detectFormat(InFlowCommand $command, ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        if ($context->source === null) {
            return $context;
        }

        $presenter->presentStepProgress($this->stepProgressFormatter->format(4, 8, 'Detecting file format...'));
        $presenter->presentMessage($this->messageFormatter->info('Analyzing file structure to detect format, delimiter, encoding, and header presence.'));

        $format = $this->formatDetector->detect($context->source);
        $context = $context->withFormat($format);

        $presenter->presentMessage($this->messageFormatter->success('Format detected successfully'));
        $presenter->presentFormatInfo($this->formatInfoFormatter->format($format));

        if ($presenter->presentStepSummary($this->stepSummaryFormatter->format('Format detected: '.$format->type->value, []))) {
            return $context;
        }

        return $context->withCancelled();
    }

    /**
     * Step 5: Create reader based on format.
     */
    private function createReader(InFlowCommand $command, ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        if ($context->source === null || $context->format === null) {
            return $context;
        }

        $reader = null;

        if ($context->format->type->isCsv()) {
            $presenter->presentStepProgress($this->stepProgressFormatter->format(5, 8, 'Reading CSV data...'));
            $reader = $this->readCsvData($command, $context->source, $context->format, $presenter);
        } elseif ($context->format->type->isExcel()) {
            $presenter->presentStepProgress($this->stepProgressFormatter->format(5, 8, 'Reading Excel data...'));
            $reader = $this->readExcelData($command, $context->source, $context->format, $presenter);
        } elseif ($context->format->type->isJson()) {
            $presenter->presentStepProgress($this->stepProgressFormatter->format(5, 8, 'Reading JSON Lines data...'));
            $reader = $this->readJsonData($command, $context->source, $context->format, $presenter);
        } elseif ($context->format->type->isXml()) {
            $presenter->presentStepProgress($this->stepProgressFormatter->format(5, 8, 'Reading XML data...'));
            $reader = $this->readXmlData($command, $context->source, $context->format, $presenter);
        } else {
            $presenter->presentMessage($this->messageFormatter->warning('Data reading skipped (unsupported file type: '.$context->format->type->value.')'));
        }

        if ($reader !== null) {
            $context = $context->withReader($reader);
        }

        if ($presenter->presentStepSummary($this->stepSummaryFormatter->format('Data read completed', []))) {
            return $context;
        }

        return $context->withCancelled();
    }

    /**
     * Step 6: Profile data if no mapping provided.
     */
    private function profileData(InFlowCommand $command, ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        if ($context->reader === null) {
            $presenter->presentMessage($this->messageFormatter->warning('Profiling skipped (no data reader available)'));

            return $context;
        }

        $presenter->presentStepProgress($this->stepProgressFormatter->format(6, 8, 'Profiling data quality...'));
        $presenter->presentMessage($this->messageFormatter->info('Analyzing data structure, types, and quality issues. This helps identify problems before import.'));

        $context->reader->rewind();
        $result = $this->profiler->profile($context->reader);
        $schema = $result['schema'];
        $qualityReport = $result['quality_report'];

        $context = $context->withSourceSchema($schema);

        $presenter->presentMessage($this->messageFormatter->success('Profiling completed'));
        $presenter->presentProgressInfo($this->progressInfoFormatter->format(
            'Analyzed',
            rows: $schema->totalRows,
            columns: count($schema->columns)
        ));

        $presenter->presentSchema($this->schemaFormatter->format($schema));
        $presenter->presentQualityReport($this->qualityReportFormatter->format($qualityReport));

        if ($presenter->presentStepSummary($this->stepSummaryFormatter->format('Data profiling', [
            'Rows analyzed' => number_format($schema->totalRows),
            'Columns detected' => (string) count($schema->columns),
            'Quality issues' => $qualityReport->hasIssues() ? 'Yes (see above)' : 'None',
        ]))) {
            return $context;
        }

        return $context->withCancelled();
    }

    /**
     * Step 7: Process mapping definition.
     */
    private function processMapping(InFlowCommand $command, ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        if ($context->reader === null || $context->sourceSchema === null) {
            $presenter->presentMessage($this->messageFormatter->warning('Mapping skipped (no schema available)'));

            return $context;
        }

        $presenter->presentStepProgress($this->stepProgressFormatter->format(7, 8, 'Processing mapping...'));

        $mappingDefinition = $this->loadOrGenerateMapping($command, $context->sourceSchema, $presenter);

        if ($mappingDefinition === null) {
            return $context->withCancelled();
        }

        $context = $context->withMappingDefinition($mappingDefinition);

        $columnCount = 0;
        $relationCount = 0;
        $modelClass = '';

        foreach ($mappingDefinition->mappings as $modelMapping) {
            $modelClass = $modelMapping->modelClass;
            foreach ($modelMapping->columns as $column) {
                if (str_contains($column->targetPath, '.')) {
                    $relationCount++;
                } else {
                    $columnCount++;
                }
            }
        }

        if ($presenter->presentStepSummary($this->stepSummaryFormatter->format('Mapping configuration', [
            'Model' => class_basename($modelClass),
            'Direct fields' => (string) $columnCount,
            'Relation fields' => (string) $relationCount,
        ]))) {
            return $context;
        }

        return $context->withCancelled();
    }

    /**
     * Step 8: Execute flow.
     */
    private function executeFlow(InFlowCommand $command, ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        if ($context->mappingDefinition === null || $context->reader === null || $context->format === null) {
            $presenter->presentMessage($this->messageFormatter->warning('Flow execution skipped (no mapping available)'));

            return $context;
        }

        $presenter->presentStepProgress($this->stepProgressFormatter->format(8, 8, 'Executing ETL flow...'));
        $presenter->presentMessage($this->messageFormatter->info('Importing data into database. Rows are processed in chunks for optimal performance.'));

        $shouldSanitize = $context->shouldSanitize ?? true;
        $sanitizerConfig = $this->getSanitizerConfig($command);

        $flow = new Flow(
            sourceConfig: [
                'path' => $context->filePath,
                'type' => SourceType::File->value,
            ],
            sanitizerConfig: $shouldSanitize ? $sanitizerConfig : [],
            formatConfig: null, // Auto-detect
            mapping: $context->mappingDefinition,
            options: [
                'chunk_size' => $this->configResolver->getReaderConfig('chunk_size', 1000),
                'error_policy' => ErrorPolicy::Continue->value,
            ],
            name: 'Command Flow: '.basename($context->filePath),
            description: 'Flow created from command execution'
        );

        $executor = new FlowExecutor(
            $this->flowExecutionService,
            $this->profiler,
            $this->eloquentLoader,
            $this->mappingValidator,
            $this->dependencyValidator,
            $this->pivotSyncService,
            null, // progressCallback
            null  // errorDecisionCallback
        );

        $flowRun = $executor->execute($flow, $context->filePath);
        $context = $context->withFlowRun($flowRun);

        $presenter->presentFlowRun($this->flowRunFormatter->format($flowRun));

        return $context;
    }

    // Helper methods for file operations

    private function readFileContent(InFlowCommand $command, FileSource $source): string
    {
        $fileSize = $source->getSize();
        $threshold = 10240; // 10KB

        if ($fileSize > $threshold && ! $command->isQuiet()) {
            return $this->readFileContentWithProgress($source, $fileSize);
        }

        return $this->fileReader->read($source);
    }

    private function readFileContentWithProgress(FileSource $source, int $fileSize): string
    {
        $progressBar = \Laravel\Prompts\progress(
            label: 'Reading file',
            steps: $fileSize
        );

        $content = $this->fileReader->read(
            $source,
            function (int $bytesRead, int $totalBytes, int $lineCount, int $chunkSize) use ($progressBar) {
                $progressBar->label('Reading file (lines: '.number_format($lineCount).')');
                $progressBar->advance($chunkSize);
            }
        );

        $progressBar->finish();

        return $content;
    }

    private function readCsvData(InFlowCommand $command, FileSource $source, DetectedFormat $format, PresenterInterface $presenter): ReaderInterface
    {
        return $this->readDataWithPreview($command, new CsvReader($source, $format), $presenter);
    }

    private function readExcelData(InFlowCommand $command, FileSource $source, DetectedFormat $format, PresenterInterface $presenter): ReaderInterface
    {
        return $this->readDataWithPreview($command, new ExcelReader($source, $format), $presenter);
    }

    private function readJsonData(InFlowCommand $command, FileSource $source, DetectedFormat $format, PresenterInterface $presenter): ReaderInterface
    {
        return $this->readDataWithPreview($command, new JsonLinesReader($source, $format), $presenter);
    }

    private function readXmlData(InFlowCommand $command, FileSource $source, DetectedFormat $format, PresenterInterface $presenter): ReaderInterface
    {
        return $this->readDataWithPreview($command, new XmlReader($source), $presenter);
    }

    private function readDataWithPreview(InFlowCommand $command, ReaderInterface $reader, PresenterInterface $presenter): ReaderInterface
    {
        $previewRows = (int) $this->configResolver->resolveOptionWithFallback(
            'preview',
            fn (string $key) => $command->option($key),
            5
        );

        $previewData = $this->readPreview($reader, $previewRows);
        $rows = $previewData['rows'];
        $rowCount = $previewData['count'];

        $presenter->presentProgressInfo($this->progressInfoFormatter->format('Read', rows: $rowCount));

        if (! empty($rows) && ! $command->isQuiet()) {
            $presenter->presentPreview($this->previewFormatter->format($reader, $rows, $previewRows));
        }

        return $reader;
    }

    // Helper methods for configuration and decisions

    private function determineShouldSanitize(InFlowCommand $command, ProcessingContext $context): bool
    {
        $sanitizeOption = $command->option('sanitize');
        $wasSanitizePassed = $command->hasParameterOption('--sanitize', true);

        if ($wasSanitizePassed) {
            return $this->parseBooleanValue($sanitizeOption);
        }

        if (isset($context->guidedConfig['sanitize'])) {
            return (bool) $context->guidedConfig['sanitize'];
        }

        $configDefault = $this->configResolver->getConfigDefault('sanitize');
        if ($configDefault !== null) {
            return (bool) $configDefault;
        }

        if ($command->isQuiet()) {
            return true;
        }

        if (! $command->option('no-interaction')) {
            return $command->confirm('  Do you want to sanitize the file (remove BOM, normalize newlines, etc.)?', true);
        }

        return false;
    }

    private function parseBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return true;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'enabled'], true);
    }

    private function getSanitizerConfig(InFlowCommand $command): array
    {
        return $this->configResolver->buildSanitizerConfig(
            fn (string $key) => $command->option($key)
        );
    }

    private function loadOrGenerateMapping(InFlowCommand $command, SourceSchema $sourceSchema, PresenterInterface $presenter): ?MappingDefinition
    {
        $mappingPath = $command->option('mapping');
        $modelClass = $command->argument('to');

        if ($modelClass === null) {
            $presenter->presentMessage($this->messageFormatter->error('Model class is required. Use: inflow:process file.csv App\\Models\\User'));

            return null;
        }

        // If mapping file is explicitly provided, load it
        if ($mappingPath !== null) {
            try {
                $mapping = $this->mappingSerializer->loadFromFile($mappingPath);
                $presenter->presentProgressInfo($this->progressInfoFormatter->format('Mapping loaded from: '.$mappingPath));

                return $mapping;
            } catch (\Exception $e) {
                \inflow_report($e, 'error', ['operation' => 'loadMapping', 'path' => $mappingPath]);
                $presenter->presentMessage($this->messageFormatter->error('Failed to load mapping: '.$e->getMessage()));

                return null;
            }
        }

        // Try to find existing mapping
        $existingMappingPath = $this->configResolver->findMappingForModel($modelClass);
        if ($existingMappingPath !== null && ! $command->isQuiet() && ! $command->option('no-interaction')) {
            $presenter->presentMessage($this->messageFormatter->info('Found existing mapping: '.$existingMappingPath));
            $useExisting = \Laravel\Prompts\confirm(label: '  Use existing mapping?', default: false, yes: 'y', no: 'n');

            if ($useExisting) {
                try {
                    $mapping = $this->mappingSerializer->loadFromFile($existingMappingPath);
                    $presenter->presentProgressInfo($this->progressInfoFormatter->format('Mapping loaded from: '.$existingMappingPath.' (auto-detected)'));

                    return $mapping;
                } catch (\Exception $e) {
                    \inflow_report($e, 'warning', ['operation' => 'loadExistingMapping', 'model' => $modelClass]);
                    $presenter->presentMessage($this->messageFormatter->warning('Failed to load existing mapping, generating new one...'));
                }
            }
        }

        // Generate new mapping
        try {
            $filePath = $command->argument('from');
            $mapping = $this->mappingBuilder->autoMapInteractive(
                schema: $sourceSchema,
                modelClass: $modelClass,
                interactiveCallback: function ($sourceColumn, $suggestedPath, $alternatives) use ($command) {
                    // Simplified mapping interaction - delegate to command if needed
                    return $command->choice(
                        "Map '{$sourceColumn}' to:",
                        array_merge([$suggestedPath => $suggestedPath], array_combine($alternatives, $alternatives)),
                        $suggestedPath
                    );
                },
                transformCallback: function ($suggestedTransforms) {
                    return $suggestedTransforms; // Simplified - just use suggested
                }
            );

            $presenter->presentMessage($this->messageFormatter->success('Mapping generated successfully'));
            $columnCount = count($mapping->mappings[0]->columns ?? []);
            $presenter->presentProgressInfo($this->progressInfoFormatter->format('Mapped', columns: $columnCount));

            // Save mapping
            $saveMappingPath = $this->mappingGenerationService->getMappingSavePath($modelClass);
            $mappingName = $this->mappingGenerationService->generateMappingName($modelClass, $filePath);
            $mappingDescription = $this->mappingGenerationService->generateMappingDescription($modelClass, $filePath);

            $mapping = new MappingDefinition(
                mappings: $mapping->mappings,
                name: $mappingName,
                description: $mappingDescription,
                sourceSchema: $mapping->sourceSchema
            );

            $this->mappingGenerationService->saveMapping($mapping, $saveMappingPath);
            $presenter->presentProgressInfo($this->progressInfoFormatter->format('Mapping saved to: '.$saveMappingPath));

            return $mapping;
        } catch (\Exception $e) {
            \inflow_report($e, 'error', ['operation' => 'generateMapping', 'model' => $modelClass]);
            $presenter->presentMessage($this->messageFormatter->error('Failed to generate mapping: '.$e->getMessage()));

            return null;
        }
    }

    /**
     * Count lines in content.
     *
     * Handles different newline formats: LF, CRLF, and CR.
     */
    private function countLines(string $content): int
    {
        if (empty($content)) {
            return 0;
        }

        // Count newlines (handles LF, CRLF, CR)
        return substr_count($content, "\n") + substr_count($content, "\r") - substr_count($content, "\r\n") + 1;
    }

    /**
     * Read preview rows from a reader.
     *
     * @param  ReaderInterface  $reader  The reader to read from
     * @param  int  $maxRows  Maximum number of rows to read
     * @return array{rows: array<int, array<string, mixed>>, count: int} Array of rows and count
     */
    private function readPreview(ReaderInterface $reader, int $maxRows): array
    {
        $rows = [];
        $rowCount = 0;

        foreach ($reader as $row) {
            $rows[] = $row;
            $rowCount++;
            if ($rowCount >= $maxRows) {
                break;
            }
        }

        return [
            'rows' => $rows,
            'count' => $rowCount,
        ];
    }
}
