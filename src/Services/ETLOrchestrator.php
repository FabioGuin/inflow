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
use InFlow\Services\Formatter\FlowRunFormatter;
use InFlow\Services\Formatter\FormatInfoFormatter;
use InFlow\Services\Formatter\MessageFormatter;
use InFlow\Services\Formatter\PreviewFormatter;
use InFlow\Services\Formatter\QualityReportFormatter;
use InFlow\Services\Formatter\SchemaFormatter;
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
        private MessageFormatter $messageFormatter
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
        $context = $this->readContent($command, $context);
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
        $command->infoLine('<fg=blue>Step 1/8:</> <fg=gray>Loading file...</>');

        try {
            $source = FileSource::fromPath($context->filePath);
            $context = $context->withSource($source);

            $presenter->presentMessage($this->messageFormatter->success('File loaded successfully'));
            $this->displayFileInfo($command, $source);

            if ($this->shouldContinue($command, 'File loaded', [
                'Name' => $source->getName(),
                'Size' => $this->fileWriter->formatSize($source->getSize()),
            ])) {
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
    private function readContent(InFlowCommand $command, ProcessingContext $context): ProcessingContext
    {
        if ($context->source === null) {
            return $context;
        }

        $command->infoLine('<fg=blue>Step 2/8:</> <fg=gray>Reading file content...</>');

        $content = $this->readFileContent($command, $context->source);
        $lineCount = $this->countLines($content);

        $context = $context
            ->withContent($content)
            ->withLineCount($lineCount);

        $command->infoLine('<fg=green>✓ Content read:</> <fg=yellow>'.number_format($lineCount).'</> lines, <fg=yellow>'.number_format(strlen($content)).'</> bytes');

        if ($this->shouldContinue($command, 'Content read', [
            'Lines' => number_format($lineCount),
            'Bytes' => number_format(strlen($content)),
        ])) {
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
            $command->infoLine('<fg=blue>Step 3/8:</> <fg=gray>Sanitizing content...</>');
            $command->note('Cleaning file: removing BOM, normalizing newlines, removing control characters.');

            $lineCount = $context->lineCount ?? 0;
            $sanitizerConfig = $this->getSanitizerConfig($command);
            [$sanitized] = $this->sanitizationService->sanitize($context->content, $sanitizerConfig);
            $newLineCount = $this->countLines($sanitized);

            if ($newLineCount !== $lineCount && ! $command->isQuiet()) {
                $command->note("Line count changed: {$lineCount} → {$newLineCount} (normalization effect)", 'warning');
            }

            $context = $context
                ->withContent($sanitized)
                ->withLineCount($newLineCount)
                ->withShouldSanitize(true);

            $presenter->presentMessage($this->messageFormatter->success('Sanitization completed'));
            // Sanitization report display removed (was empty)

            if ($this->shouldContinue($command, 'Sanitization', [
                'Lines' => (string) $newLineCount,
                'Status' => 'cleaned',
            ])) {
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

        $command->infoLine('<fg=blue>Step 4/8:</> <fg=gray>Detecting file format...</>');
        $command->note('Analyzing file structure to detect format, delimiter, encoding, and header presence.');

        $format = $this->formatDetector->detect($context->source);
        $context = $context->withFormat($format);

        $presenter->presentMessage($this->messageFormatter->success('Format detected successfully'));
        $presenter->presentFormatInfo($this->formatInfoFormatter->format($format));

        if ($this->shouldContinue($command, 'Format detected: '.$format->type->value)) {
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
            $command->infoLine('<fg=blue>Step 5/8:</> <fg=gray>Reading CSV data...</>');
            $reader = $this->readCsvData($command, $context->source, $context->format, $presenter);
        } elseif ($context->format->type->isExcel()) {
            $command->infoLine('<fg=blue>Step 5/8:</> <fg=gray>Reading Excel data...</>');
            $reader = $this->readExcelData($command, $context->source, $context->format, $presenter);
        } elseif ($context->format->type->isJson()) {
            $command->infoLine('<fg=blue>Step 5/8:</> <fg=gray>Reading JSON Lines data...</>');
            $reader = $this->readJsonData($command, $context->source, $context->format, $presenter);
        } elseif ($context->format->type->isXml()) {
            $command->infoLine('<fg=blue>Step 5/8:</> <fg=gray>Reading XML data...</>');
            $reader = $this->readXmlData($command, $context->source, $context->format, $presenter);
        } else {
            $presenter->presentMessage($this->messageFormatter->warning('Data reading skipped (unsupported file type: '.$context->format->type->value.')'));
        }

        if ($reader !== null) {
            $context = $context->withReader($reader);
        }

        $command->newLine();

        if ($this->shouldContinue($command, 'Data read completed')) {
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
            $command->newLine();

            return $context;
        }

        $command->infoLine('<fg=blue>Step 6/8:</> <fg=gray>Profiling data quality...</>');
        $command->note('Analyzing data structure, types, and quality issues. This helps identify problems before import.');

        $context->reader->rewind();
        $result = $this->profiler->profile($context->reader);
        $schema = $result['schema'];
        $qualityReport = $result['quality_report'];

        $context = $context->withSourceSchema($schema);

        $presenter->presentMessage($this->messageFormatter->success('Profiling completed'));
        $command->infoLine('  <fg=gray>→</> Analyzed <fg=yellow>'.number_format($schema->totalRows).'</> row(s)');
        $command->infoLine('  <fg=gray>→</> Detected <fg=yellow>'.count($schema->columns).'</> column(s)');

        $presenter->presentSchema($this->schemaFormatter->format($schema));
        $presenter->presentQualityReport($this->qualityReportFormatter->format($qualityReport));

        if ($this->shouldContinue($command, 'Data profiling', [
            'Rows analyzed' => number_format($schema->totalRows),
            'Columns detected' => (string) count($schema->columns),
            'Quality issues' => $qualityReport->hasIssues() ? 'Yes (see above)' : 'None',
        ])) {
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
            $command->newLine();

            return $context;
        }

        $command->infoLine('<fg=blue>Step 7/8:</> <fg=gray>Processing mapping...</>');

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

        if ($this->shouldContinue($command, 'Mapping configuration', [
            'Model' => class_basename($modelClass),
            'Direct fields' => (string) $columnCount,
            'Relation fields' => (string) $relationCount,
        ])) {
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

        $command->infoLine('<fg=blue>Step 8/8:</> <fg=gray>Executing ETL flow...</>');
        $command->note('Importing data into database. Rows are processed in chunks for optimal performance.');

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

        $command->infoLine('<fg=green>✓ Read</> <fg=yellow>'.$rowCount.'</> row(s)');

        if (! empty($rows) && ! $command->isQuiet()) {
            $presenter->presentPreview($this->previewFormatter->format($reader, $rows, $previewRows));
        }

        return $reader;
    }

    // Helper methods for display/formatting (consolidated from formatter services)

    private function displayFileInfo(InFlowCommand $command, FileSource $source): void
    {
        if ($command->isQuiet()) {
            return;
        }

        $command->newLine();
        $command->infoLine('File Information');
        $command->line('─────────────────────────────────────────────────────────');

        $sizeFormatted = $this->fileWriter->formatSize($source->getSize());

        $command->table(
            ['Property', 'Value'],
            [
                ['Name', $source->getName()],
                ['Extension', $source->getExtension() ?: '<fg=gray>none</>'],
                ['Size', $sizeFormatted],
                ['MIME Type', $source->getMimeType() ?: '<fg=gray>unknown</>'],
            ]
        );
        $command->newLine();
    }

    // Helper methods for configuration and decisions

    private function shouldContinue(InFlowCommand $command, string $stepName, array $summary = []): bool
    {
        if ($command->isQuiet() || $command->option('no-interaction')) {
            return true;
        }

        $command->newLine();
        $command->line('<fg=green>✓</> <fg=white;options=bold>'.$stepName.' completed</>');

        if (! empty($summary)) {
            foreach ($summary as $label => $value) {
                $command->line('  <fg=gray>'.$label.':</> <fg=white>'.$value.'</>');
            }
        }

        $command->newLine();

        $choice = \Laravel\Prompts\select(
            label: '  Continue?',
            options: [
                'continue' => '▶ Continue to next step',
                'cancel' => '✕ Cancel import',
            ],
            default: 'continue'
        );

        return $choice === 'continue';
    }

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
                $command->infoLine('<fg=green>✓ Mapping loaded from:</> <fg=yellow>'.$mappingPath.'</>');

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
            $command->line('<fg=cyan>Found existing mapping:</> <fg=yellow>'.$existingMappingPath.'</>');
            $useExisting = \Laravel\Prompts\confirm(label: '  Use existing mapping?', default: false, yes: 'y', no: 'n');

            if ($useExisting) {
                try {
                    $mapping = $this->mappingSerializer->loadFromFile($existingMappingPath);
                    $command->infoLine('<fg=green>✓ Mapping loaded from:</> <fg=yellow>'.$existingMappingPath.'</> (auto-detected)');

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
            $command->infoLine('  <fg=gray>→</> <fg=yellow>'.$columnCount.'</> column(s) mapped');

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
            $command->infoLine('  <fg=gray>→</> Mapping saved to: <fg=yellow>'.$saveMappingPath.'</>');

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
