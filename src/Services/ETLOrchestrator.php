<?php

namespace InFlow\Services;

use InFlow\Commands\InFlowCommand;
use InFlow\Contracts\ReaderInterface;
use InFlow\Detectors\FormatDetector;
use InFlow\Enums\File\NewlineFormat;
use InFlow\Enums\UI\MessageType;
use InFlow\Presenters\Contracts\PresenterInterface;
use InFlow\Profilers\Profiler;
use InFlow\Readers\CsvReader;
use InFlow\Readers\ExcelReader;
use InFlow\Readers\JsonLinesReader;
use InFlow\Readers\XmlReader;
use InFlow\Sanitizers\SanitizerConfigKeys;
use InFlow\Services\Core\ConfigurationResolver;
use InFlow\Services\DataProcessing\SanitizationService;
use InFlow\Services\File\FileReaderService;
use InFlow\Services\File\FileWriterService;
use Illuminate\Database\Eloquent\Model;
use InFlow\Loaders\EloquentLoader;
use InFlow\Transforms\TransformEngine;
use InFlow\ValueObjects\Data\Row;
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
use InFlow\Sources\FileSource;
use InFlow\Enums\Data\ColumnType;
use InFlow\Enums\File\FileType;
use InFlow\ValueObjects\Data\ColumnMetadata;
use InFlow\ValueObjects\Data\SourceSchema;
use InFlow\ValueObjects\File\DetectedFormat;
use InFlow\ValueObjects\Flow\ProcessingContext;

/**
 * Simplified ETL orchestrator that replaces the pipeline and pipes.
 *
 * Consolidates all ETL steps into a single linear flow:
 * 1. Load file
 * 2. Read content (if sanitization needed)
 * 3. Sanitize (if needed)
 * 4. Detect format (or use from mapping)
 * 5. Create reader
 * 6. Profile data (skip if source_schema in mapping)
 * 7. Execute flow (mapping already loaded in context)
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
        private EloquentLoader $eloquentLoader,
        private TransformEngine $transformEngine,
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
        $flowConfig = $context->mappingDefinition['flow_config'] ?? null;

        // Step 1: Load file
        $context = $this->loadFile($context, $presenter);
        if ($context->cancelled || $context->source === null) {
            return $context;
        }

        // Step 2: Read content (only if sanitization needed)
        $shouldSanitize = $this->shouldSanitize($command, $flowConfig);
        if ($shouldSanitize) {
            $context = $this->readContent($command, $context, $presenter);
            if ($context->cancelled || $context->content === null) {
                return $context;
            }
        }

        // Step 3: Sanitize (if needed and not already configured in mapping)
        if ($shouldSanitize) {
            $context = $this->sanitize($command, $context, $presenter, $shouldSanitize, $flowConfig);
            if ($context->cancelled) {
                return $context;
            }
        }

        // Step 4: Detect format (or use from mapping)
        $context = $this->detectFormat($context, $presenter, $flowConfig);
        if ($context->cancelled || $context->format === null) {
            return $context;
        }

        // Step 5: Create reader
        $context = $this->createReader($command, $context, $presenter);
        if ($context->cancelled || $context->reader === null) {
            return $context;
        }

        // Step 6: Profile data (skip if source_schema in mapping)
        $context = $this->profileData($context, $presenter);
        if ($context->cancelled) {
            return $context;
        }

        // Step 7: Execute flow (mapping is already loaded in context)
        $context = $this->executeFlow($command, $context, $presenter);

        return $context;
    }

    /**
     * Step 1: Load file and create FileSource.
     */
    private function loadFile(ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        $presenter->presentStepProgress($this->stepProgressFormatter->format(1, 7, 'Loading file...'));

        try {
            $source = FileSource::fromPath($context->filePath);
            $context = $context->withSource($source);

            $presenter->presentMessage($this->messageFormatter->format('File loaded successfully', MessageType::Success));
            $presenter->presentFileInfo($this->fileInfoFormatter->format($source));

            if ($presenter->presentStepSummary($this->stepSummaryFormatter->format('File loaded', [
                'Name' => $source->getName(),
                'Size' => $this->fileWriter->formatSize($source->getSize()),
            ]))) {
                return $context;
            }

            return $context->withCancelled();
        } catch (\RuntimeException $e) {
            $presenter->presentMessage($this->messageFormatter->format('Failed to load file: '.$e->getMessage(), MessageType::Error));
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

        $presenter->presentStepProgress($this->stepProgressFormatter->format(2, 7, 'Reading file content...'));

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
     * Determine if sanitization should be performed.
     *
     * Priority:
     * 1. Command option (--sanitize) - explicit override
     * 2. Mapping flow_config.sanitizer.enabled - for recurring processes
     * 3. Config file - fallback
     */
    private function shouldSanitize(InFlowCommand $command, ?array $flowConfig): bool
    {
        // Priority 1: Command option (explicit override)
        $sanitizeOption = $command->option('sanitize');
        $wasSanitizePassed = $command->hasParameterOption('--sanitize', true);
        if ($wasSanitizePassed) {
            return $this->parseBooleanValue($sanitizeOption);
        }

        // Priority 2: Mapping flow_config (for recurring processes)
        if ($flowConfig !== null && isset($flowConfig['sanitizer'])) {
            return $flowConfig['sanitizer']['enabled'] ?? false;
        }

        // Priority 3: Config file (fallback)
        $sanitizerEnabled = $this->configResolver->getSanitizerConfig('enabled', true);

        return is_bool($sanitizerEnabled) ? $sanitizerEnabled : true;
    }

    /**
     * Step 3: Sanitize content if needed.
     */
    private function sanitize(InFlowCommand $command, ProcessingContext $context, PresenterInterface $presenter, bool $shouldSanitize, ?array $flowConfig = null): ProcessingContext
    {
        if ($context->content === null) {
            return $context;
        }

        if ($shouldSanitize) {
            $presenter->presentStepProgress($this->stepProgressFormatter->format(3, 7, 'Sanitizing content...'));
            $presenter->presentMessage($this->messageFormatter->format('Cleaning file: removing BOM, normalizing newlines, removing control characters.', MessageType::Info));

            $lineCount = $context->lineCount ?? 0;

            // Use sanitizer config from flow_config if available, otherwise from command/config
            if ($flowConfig !== null && isset($flowConfig['sanitizer'])) {
                $sanitizerConfig = $this->normalizeSanitizerConfigFromJson($flowConfig['sanitizer']);
            } else {
                $sanitizerConfig = $this->configResolver->buildSanitizerConfig(
                    fn (string $key) => $command->option($key)
                );
            }
            [$sanitized] = $this->sanitizationService->sanitize($context->content, $sanitizerConfig);
            $newLineCount = $this->countLines($sanitized);

            if ($newLineCount !== $lineCount) {
                $presenter->presentMessage($this->messageFormatter->format("Line count changed: {$lineCount} → {$newLineCount} (normalization effect)", MessageType::Warning));
            }

            $context = $context
                ->withContent($sanitized)
                ->withLineCount($newLineCount)
                ->withShouldSanitize(true);

            $presenter->presentMessage($this->messageFormatter->format('Sanitization completed', MessageType::Success));

            if ($presenter->presentStepSummary($this->stepSummaryFormatter->format('Sanitization', [
                'Lines' => (string) $newLineCount,
                'Status' => 'cleaned',
            ]))) {
                return $context;
            }

            return $context->withCancelled();
        }

        $presenter->presentMessage($this->messageFormatter->format('Sanitization skipped', MessageType::Warning));

        return $context->withShouldSanitize(false);
    }

    /**
     * Step 4: Detect file format (or use from mapping).
     */
    private function detectFormat(ProcessingContext $context, PresenterInterface $presenter, ?array $flowConfig = null): ProcessingContext
    {
        if ($context->source === null) {
            return $context;
        }

        // If flow_config has format config, use it instead of detecting
        if ($flowConfig !== null && isset($flowConfig['format'])) {
            $formatConfig = $flowConfig['format'];
            $presenter->presentMessage($this->messageFormatter->format('Using format configuration from mapping file', MessageType::Info));

            // Create DetectedFormat from flow_config
            $format = $this->createFormatFromConfig($formatConfig, $context->source);
            if ($format !== null) {
                return $context->withFormat($format);
            }
        }

        // Otherwise, detect format
        $presenter->presentStepProgress($this->stepProgressFormatter->format(4, 7, 'Detecting file format...'));
        $presenter->presentMessage($this->messageFormatter->format('Analyzing file structure to detect format, delimiter, encoding, and header presence.', MessageType::Info));

        $format = $this->formatDetector->detect($context->source);
        $context = $context->withFormat($format);

        $presenter->presentMessage($this->messageFormatter->format('Format detected successfully', MessageType::Success));
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
            $presenter->presentStepProgress($this->stepProgressFormatter->format(5, 7, 'Reading CSV data...'));
            $reader = $this->readCsvData($command, $context->source, $context->format, $presenter);
        } elseif ($context->format->type->isExcel()) {
            $presenter->presentStepProgress($this->stepProgressFormatter->format(5, 7, 'Reading Excel data...'));
            $reader = $this->readExcelData($command, $context->source, $context->format, $presenter);
        } elseif ($context->format->type->isJson()) {
            $presenter->presentStepProgress($this->stepProgressFormatter->format(5, 7, 'Reading JSON Lines data...'));
            $reader = $this->readJsonData($command, $context->source, $presenter);
        } elseif ($context->format->type->isXml()) {
            $presenter->presentStepProgress($this->stepProgressFormatter->format(5, 7, 'Reading XML data...'));
            $reader = $this->readXmlData($command, $context->source, $presenter);
        } else {
            $presenter->presentMessage($this->messageFormatter->format('Data reading skipped (unsupported file type: '.$context->format->type->value.')', MessageType::Warning));
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
     * Step 6: Profile data if no mapping provided or mapping doesn't have source_schema.
     *
     * If mapping already contains source_schema, skip profiling and use it from mapping.
     */
    private function profileData(ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        // If mapping exists and has source_schema, use it instead of profiling
        if ($context->mappingDefinition !== null && isset($context->mappingDefinition['source_schema'])) {
            $presenter->presentMessage($this->messageFormatter->format('Using source_schema from mapping file (profiling skipped)', MessageType::Info));

            $schema = $this->convertSourceSchemaFromMapping($context->mappingDefinition['source_schema']);
            if ($schema !== null) {
                $context = $context->withSourceSchema($schema);
                $presenter->presentProgressInfo($this->progressInfoFormatter->format(
                    'Loaded from mapping',
                    rows: $schema->totalRows,
                    columns: count($schema->columns)
                ));

                return $context;
            }
        }

        // No mapping or no source_schema in mapping - profile the data
        if ($context->reader === null) {
            $presenter->presentMessage($this->messageFormatter->format('Profiling skipped (no data reader available)', MessageType::Warning));

            return $context;
        }

        $presenter->presentStepProgress($this->stepProgressFormatter->format(6, 7, 'Profiling data quality...'));
        $presenter->presentMessage($this->messageFormatter->format('Analyzing data structure, types, and quality issues. This helps identify problems before import.', MessageType::Info));

        $context->reader->rewind();
        $result = $this->profiler->profile($context->reader);
        $schema = $result['schema'];
        $qualityReport = $result['quality_report'];

        $context = $context->withSourceSchema($schema);

        $presenter->presentMessage($this->messageFormatter->format('Profiling completed', MessageType::Success));
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
     * Step 7: Execute flow.
     *
     * Processes all rows from the reader using the mapping definition.
     */
    private function executeFlow(InFlowCommand $command, ProcessingContext $context, PresenterInterface $presenter): ProcessingContext
    {
        if ($context->reader === null || $context->format === null) {
            $presenter->presentMessage($this->messageFormatter->format('Flow execution skipped (no reader/format available)', MessageType::Warning));

            return $context;
        }

        if ($context->mappingDefinition === null) {
            $presenter->presentMessage($this->messageFormatter->format('Flow execution requires mapping file', MessageType::Warning));

            return $context;
        }

        $presenter->presentStepProgress($this->stepProgressFormatter->format(7, 7, 'Executing ETL flow...'));
        $presenter->presentMessage($this->messageFormatter->format('Importing data into database. Rows are processed in chunks for optimal performance.', MessageType::Info));

        // Get execution options from flow_config
        $flowConfig = $context->mappingDefinition['flow_config'] ?? [];
        $executionConfig = $flowConfig['execution'] ?? [];
        $chunkSize = $executionConfig['chunk_size'] ?? $this->configResolver->getExecutionConfig('chunk_size', 1000);
        $skipEmptyRows = $executionConfig['skip_empty_rows'] ?? $this->configResolver->getExecutionConfig('skip_empty_rows', true);
        $truncateLongFields = $executionConfig['truncate_long_fields'] ?? $this->configResolver->getExecutionConfig('truncate_long_fields', true);

        // Get mappings and sort by execution_order
        $mappings = $context->mappingDefinition['mappings'] ?? [];
        usort($mappings, fn ($a, $b) => ($a['execution_order'] ?? 0) <=> ($b['execution_order'] ?? 0));

        if (empty($mappings)) {
            $presenter->presentMessage($this->messageFormatter->format('No mappings found in mapping file', MessageType::Warning));

            return $context;
        }

        // Process rows
        $context->reader->rewind();
        $rowNumber = 0;
        $imported = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($context->reader as $rowData) {
            $rowNumber++;

            // Create Row object
            $row = new Row($rowData, $rowNumber);

            // Skip empty rows if configured
            if ($skipEmptyRows && $row->isEmpty()) {
                $skipped++;
                continue;
            }

            // Process each mapping in execution order
            // Track created models for dependent mappings (e.g., Book needs Author)
            $createdModels = [];

            foreach ($mappings as $mapping) {
                try {
                    // Skip nested mappings - they are processed inside their parent mappings
                    if ($this->isNestedMapping($mapping, $mappings)) {
                        continue;
                    }
                    
                    // Check if this mapping processes array data (e.g., Book from books JSON)
                    $isArrayMapping = $this->isArrayMapping($mapping);
                    
                    if ($isArrayMapping) {
                        // Process each element in the array
                        $arrayResults = $this->processArrayMapping($row, $mapping, $truncateLongFields, $createdModels, $mappings);
                        $imported += $arrayResults['imported'];
                        $skipped += $arrayResults['skipped'];
                        $errors += $arrayResults['errors'];
                    } else {
                        // Normal mapping: process once per row
                        $model = $this->eloquentLoader->load($row, $mapping, $truncateLongFields, $createdModels);

                        if ($model === null) {
                            $skipped++;
                        } else {
                            $imported++;
                            // Store created model for dependent mappings
                            $createdModels[$mapping['model']] = $model;
                        }
                    }
                } catch (\Exception $e) {
                    $errors++;
                    \inflow_report($e, 'error', [
                        'operation' => 'load',
                        'row' => $rowNumber,
                        'model' => $mapping['model'] ?? 'unknown',
                    ]);

                    // Continue processing other mappings even if one fails
                    // TODO: Implement error policy (continue, stop, etc.)
                }
            }

            // Update progress periodically
            if ($rowNumber % $chunkSize === 0) {
                $presenter->presentProgressInfo($this->progressInfoFormatter->format(
                    "Processing: {$imported} imported, {$skipped} skipped, {$errors} errors",
                    rows: $rowNumber
                ));
            }
        }

        // Final summary
        $presenter->presentMessage($this->messageFormatter->format('Flow execution completed', MessageType::Success));
        $presenter->presentProgressInfo($this->progressInfoFormatter->format(
            "Completed: {$imported} imported, {$skipped} skipped, {$errors} errors",
            rows: $rowNumber
        ));

        return $context;
    }

    /**
     * Check if a mapping is nested (should be processed inside another mapping).
     * 
     * A mapping is nested if:
     * - It has array targets (e.g., "tags.*.name")
     * - But the relation name in the target doesn't match the source (e.g., source="books", target="tags.*.name")
     */
    private function isNestedMapping(array $mapping, array $allMappings): bool
    {
        $columns = $mapping['columns'] ?? [];
        if (empty($columns)) {
            return false;
        }

        $firstSource = $columns[0]['source'] ?? null;
        if ($firstSource === null) {
            return false;
        }

        // Check if all columns have the same source and target contains ".*" but relation doesn't match source
        foreach ($columns as $column) {
            $source = $column['source'] ?? null;
            $target = $column['target'] ?? null;

            if ($source !== $firstSource) {
                return false; // Not all same source
            }

            if ($target !== null && str_contains($target, '.*')) {
                // Extract relation name from target (e.g., "tags.*.name" → "tags")
                if (preg_match('/^([^.*]+)\.\*\./', $target, $matches)) {
                    $targetRelation = $matches[1];
                    // If target relation doesn't match source, it's a nested mapping
                    if ($targetRelation !== $source) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if a mapping processes array data (all columns have same source and array targets).
     * 
     * Distinguishes between:
     * - Direct array mapping: source="books", target="books.*.title" (Book from books array)
     * - Nested mapping: source="books", target="tags.*.name" (Tag from tags inside books - should be processed as nested)
     */
    private function isArrayMapping(array $mapping): bool
    {
        $columns = $mapping['columns'] ?? [];
        if (empty($columns)) {
            return false;
        }

        // Check if all columns have the same source and target contains ".*"
        $firstSource = $columns[0]['source'] ?? null;
        if ($firstSource === null) {
            return false;
        }

        $allSameSource = true;
        $hasArrayTarget = false;
        $isDirectArrayMapping = false;

        foreach ($columns as $column) {
            $source = $column['source'] ?? null;
            $target = $column['target'] ?? null;

            if ($source !== $firstSource) {
                $allSameSource = false;
                break;
            }

            if ($target !== null && str_contains($target, '.*')) {
                $hasArrayTarget = true;
                
                // Check if target relation matches source (direct array mapping)
                // Example: source="books", target="books.*.title" → direct array mapping
                // Example: source="books", target="tags.*.name" → nested mapping (tags is inside books)
                if (preg_match('/^([^.*]+)\.\*\./', $target, $matches)) {
                    $targetRelation = $matches[1];
                    if ($targetRelation === $source) {
                        $isDirectArrayMapping = true;
                    }
                }
            }
        }

        // Only return true if it's a direct array mapping (not nested)
        return $allSameSource && $hasArrayTarget && $isDirectArrayMapping;
    }

    /**
     * Process a mapping that handles array data (e.g., Book from books JSON array).
     *
     * @param  array  $allMappings  All mappings to check for nested mappings (e.g., Tag inside Book)
     * @return array{imported: int, skipped: int, errors: int}
     */
    private function processArrayMapping(Row $row, array $mapping, bool $truncateLongFields, array $createdModels, array $allMappings = []): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = 0;

        $columns = $mapping['columns'] ?? [];
        if (empty($columns)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }

        // Get the source column (should be the same for all columns)
        $sourceColumn = $columns[0]['source'] ?? null;
        if ($sourceColumn === null) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }

        // Extract and decode JSON array
        $rawValue = $row->get($sourceColumn);
        if ($rawValue === null || $rawValue === '') {
            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }

        // Apply json_decode transform if present
        $firstColumn = $columns[0];
        $transforms = $firstColumn['transforms'] ?? [];
        $hasJsonDecode = in_array('json_decode', $transforms, true);

        if ($hasJsonDecode) {
            $arrayData = $this->transformEngine->apply($rawValue, ['json_decode'], ['row' => $row->toArray()]);
        } else {
            // Try to decode anyway if it looks like JSON
            $arrayData = json_decode($rawValue, true);
        }

        if (! is_array($arrayData)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }

        // Inject foreign keys from parent models (e.g., author_id for Book)
        $this->injectForeignKeys($mapping, $createdModels, $arrayData);

        // Normalize mapping for array processing: convert "books.*.title" → "title" in targets
        $normalizedMapping = $this->normalizeArrayMapping($mapping);

        // Process each element in the array
        foreach ($arrayData as $index => $arrayItem) {
            if (! is_array($arrayItem)) {
                continue;
            }

            // Inject foreign keys into each array item (they were injected at array level, now copy to item)
            $this->injectForeignKeysIntoItem($mapping, $createdModels, $arrayItem);

            // Create a "sub-row" from the array item
            $subRow = new Row($arrayItem, $row->lineNumber * 1000 + $index);

            try {
                $model = $this->eloquentLoader->load($subRow, $normalizedMapping, $truncateLongFields, $createdModels);

                if ($model === null) {
                    $skipped++;
                } else {
                    $imported++;
                    // Store created model for dependent mappings (e.g., Tag needs Book)
                    // Use the last created model of this type (for nested mappings like Tag)
                    $createdModels[$mapping['model']] = $model;
                    
                    // Process nested mappings (e.g., Tag inside Book's tags array)
                    $nestedResults = $this->processNestedMappings($subRow, $model, $mapping, $allMappings, $truncateLongFields, $createdModels);
                    $imported += $nestedResults['imported'];
                    $skipped += $nestedResults['skipped'];
                    $errors += $nestedResults['errors'];
                }
            } catch (\Exception $e) {
                $errors++;
                \inflow_report($e, 'error', [
                    'operation' => 'loadArrayItem',
                    'row' => $row->lineNumber,
                    'arrayIndex' => $index,
                    'model' => $mapping['model'] ?? 'unknown',
                ]);
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Process nested mappings (e.g., Tag inside Book's tags array).
     * 
     * @return array{imported: int, skipped: int, errors: int}
     */
    private function processNestedMappings(Row $subRow, Model $parentModel, array $parentMapping, array $allMappings, bool $truncateLongFields, array $createdModels): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        
        $parentModelClass = $parentMapping['model'];
        
        // Find nested mappings that depend on this parent model
        foreach ($allMappings as $nestedMapping) {
            $nestedModelClass = $nestedMapping['model'] ?? null;
            
            // Skip if same as parent or already processed
            if ($nestedModelClass === $parentModelClass || ! isset($nestedMapping['columns'])) {
                continue;
            }
            
            // Check if this nested mapping has targets that reference a relation of the parent
            // Example: Tag has "tags.*.name" and Book has "tags" relation
            $hasNestedTarget = false;
            $nestedRelationName = null;
            
            foreach ($nestedMapping['columns'] as $column) {
                $target = $column['target'] ?? '';
                if (preg_match('/^([^.*]+)\.\*\./', $target, $matches)) {
                    $hasNestedTarget = true;
                    $nestedRelationName = $matches[1];
                    break;
                }
            }
            
            if (! $hasNestedTarget || $nestedRelationName === null) {
                continue;
            }
            
            // Check if parent model has this relation
            try {
                if (! method_exists($parentModel, $nestedRelationName)) {
                    continue;
                }
                
                $relation = $parentModel->$nestedRelationName();
                $relatedModelClass = get_class($relation->getRelated());
                
                // If the relation points to the nested model, process it
                if ($relatedModelClass === $nestedModelClass) {
                    // Extract nested array from sub-row (e.g., tags from book item)
                    $nestedArrayData = $subRow->get($nestedRelationName);
                    
                    if (! is_array($nestedArrayData) || empty($nestedArrayData)) {
                        continue;
                    }
                    
                    // Normalize nested mapping: convert "tags.*.name" → "name"
                    $normalizedNestedMapping = $this->normalizeArrayMapping($nestedMapping);
                    
                    // Process each nested item
                    foreach ($nestedArrayData as $nestedIndex => $nestedItem) {
                        if (! is_array($nestedItem)) {
                            continue;
                        }
                        
                        // Create a sub-row from the nested item (e.g., a single tag)
                        $nestedSubRow = new Row($nestedItem, $subRow->lineNumber * 1000 + $nestedIndex);
                        
                        try {
                            $nestedModel = $this->eloquentLoader->load($nestedSubRow, $normalizedNestedMapping, $truncateLongFields, $createdModels);
                            
                            if ($nestedModel === null) {
                                $skipped++;
                            } else {
                                $imported++;
                                $createdModels[$nestedModelClass] = $nestedModel;
                                
                                // Sync BelongsToMany relation (Tag <-> Book)
                                if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
                                    $relation->syncWithoutDetaching([$nestedModel->getKey()]);
                                }
                            }
                        } catch (\Exception $e) {
                            $errors++;
                            \inflow_report($e, 'error', [
                                'operation' => 'loadNestedItem',
                                'row' => $subRow->lineNumber,
                                'nestedIndex' => $nestedIndex,
                                'model' => $nestedModelClass,
                                'nestedItem' => $nestedItem,
                                'normalizedMapping' => $normalizedNestedMapping,
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                \inflow_report($e, 'debug', [
                    'operation' => 'processNestedMappings',
                    'parentModel' => $parentModelClass,
                    'nestedModel' => $nestedModelClass,
                ]);
            }
        }
        
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Normalize array mapping: convert "books.*.title" → "title" in targets and use target as source.
     * 
     * For array mappings, the source column contains JSON array, and targets are like "books.*.title".
     * When processing each array item, we need to use "title" as both source and target.
     */
    private function normalizeArrayMapping(array $mapping): array
    {
        $normalized = $mapping;
        $normalizedColumns = [];

        foreach ($mapping['columns'] ?? [] as $column) {
            $target = $column['target'] ?? null;
            
            if ($target === null) {
                $normalizedColumns[] = $column;
                continue;
            }

            // Extract attribute name from array target (e.g., "books.*.title" → "title")
            if (preg_match('/^[^.*]+\.\*\.(.+)$/', $target, $matches)) {
                $attributeName = $matches[1];
                
                // Create normalized column: use attribute as both source and target
                $normalizedColumn = $column;
                $normalizedColumn['source'] = $attributeName; // Use attribute name as source
                $normalizedColumn['target'] = $attributeName; // Use attribute name as target
                
                // Remove json_decode transform (already decoded)
                if (isset($normalizedColumn['transforms'])) {
                    $normalizedColumn['transforms'] = array_filter(
                        $normalizedColumn['transforms'],
                        fn($t) => $t !== 'json_decode'
                    );
                    $normalizedColumn['transforms'] = array_values($normalizedColumn['transforms']);
                }
                
                $normalizedColumns[] = $normalizedColumn;
            } else {
                // Keep as-is if not an array target
                $normalizedColumns[] = $column;
            }
        }

        $normalized['columns'] = $normalizedColumns;

        return $normalized;
    }

    /**
     * Inject foreign keys from parent models into a single array item.
     * 
     * For example, if Book needs author_id, inject it from the created Author model.
     */
    private function injectForeignKeysIntoItem(array $mapping, array $createdModels, array &$arrayItem): void
    {
        $modelClass = $mapping['model'];
        
        // Use ModelDependencyService to find BelongsTo relations
        $dependencyService = app(\InFlow\Services\Mapping\ModelDependencyService::class);
        $dependencies = $dependencyService->analyzeDependencies($modelClass);
        
        // For each BelongsTo relation, inject the foreign key
        foreach ($dependencies['belongsTo'] as $relationName => $relatedModelClass) {
            // Check if we have the parent model created
            if (! isset($createdModels[$relatedModelClass])) {
                continue;
            }
            
            $parentModel = $createdModels[$relatedModelClass];
            $parentId = $parentModel->getKey();
            
            // Get the foreign key name for this relation
            try {
                $model = new $modelClass;
                if (! method_exists($model, $relationName)) {
                    continue;
                }
                
                $relation = $model->$relationName();
                if (! method_exists($relation, 'getForeignKeyName')) {
                    continue;
                }
                
                $foreignKey = $relation->getForeignKeyName();
                
                // Inject foreign key into array item if not already set
                if (! isset($arrayItem[$foreignKey])) {
                    $arrayItem[$foreignKey] = $parentId;
                    \inflow_report(new \Exception("Injected {$foreignKey} = {$parentId}"), 'debug', [
                        'operation' => 'injectForeignKeysIntoItem',
                        'model' => $modelClass,
                        'relation' => $relationName,
                        'foreignKey' => $foreignKey,
                        'parentId' => $parentId,
                    ]);
                }
            } catch (\Exception $e) {
                \inflow_report($e, 'debug', [
                    'operation' => 'injectForeignKeysIntoItem',
                    'model' => $modelClass,
                    'relation' => $relationName,
                ]);
            }
        }
    }

    /**
     * Inject foreign keys from parent models into array data.
     * 
     * For example, if Book needs author_id, inject it from the created Author model.
     */
    private function injectForeignKeys(array $mapping, array $createdModels, array &$arrayData): void
    {
        $modelClass = $mapping['model'];
        
        // Use ModelDependencyService to find BelongsTo relations
        $dependencyService = app(\InFlow\Services\Mapping\ModelDependencyService::class);
        $dependencies = $dependencyService->analyzeDependencies($modelClass);
        
        // For each BelongsTo relation, inject the foreign key
        foreach ($dependencies['belongsTo'] as $relationName => $relatedModelClass) {
            // Check if we have the parent model created
            if (! isset($createdModels[$relatedModelClass])) {
                continue;
            }
            
            $parentModel = $createdModels[$relatedModelClass];
            $parentId = $parentModel->getKey();
            
            // Get the foreign key name for this relation
            try {
                $model = new $modelClass;
                if (! method_exists($model, $relationName)) {
                    continue;
                }
                
                $relation = $model->$relationName();
                if (! method_exists($relation, 'getForeignKeyName')) {
                    continue;
                }
                
                $foreignKey = $relation->getForeignKeyName();
                
                // Inject foreign key into each array item
                foreach ($arrayData as &$item) {
                    if (is_array($item) && ! isset($item[$foreignKey])) {
                        $item[$foreignKey] = $parentId;
                    }
                }
                unset($item);
            } catch (\Exception $e) {
                \inflow_report($e, 'debug', [
                    'operation' => 'injectForeignKeys',
                    'model' => $modelClass,
                    'relation' => $relationName,
                ]);
            }
        }
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

    private function readJsonData(InFlowCommand $command, FileSource $source, PresenterInterface $presenter): ReaderInterface
    {
        return $this->readDataWithPreview($command, new JsonLinesReader($source), $presenter);
    }

    private function readXmlData(InFlowCommand $command, FileSource $source, PresenterInterface $presenter): ReaderInterface
    {
        return $this->readDataWithPreview($command, new XmlReader($source), $presenter);
    }

    private function readDataWithPreview(InFlowCommand $command, ReaderInterface $reader, PresenterInterface $presenter): ReaderInterface
    {
        // Get preview rows from command option, fallback to config, then default to 5
        $previewOption = $command->option('preview');
        $previewRows = $previewOption !== null
            ? (int) $previewOption
            : $this->configResolver->getExecutionConfig('preview_rows', 5);

        $previewData = $this->readPreview($reader, $previewRows);
        $rows = $previewData['rows'];
        $rowCount = $previewData['count'];

        $presenter->presentProgressInfo($this->progressInfoFormatter->format('Read', rows: $rowCount));

        if (! empty($rows) && ! $command->isQuiet()) {
            $presenter->presentPreview($this->previewFormatter->format($reader, $rows, $previewRows));
        }

        return $reader;
    }

    /**
     * Create DetectedFormat from flow_config format configuration.
     */
    private function createFormatFromConfig(array $formatConfig, FileSource $source): ?DetectedFormat
    {
        try {
            $type = FileType::tryFrom($formatConfig['type'] ?? '');
            if ($type === null) {
                return null;
            }

            return new DetectedFormat(
                type: $type,
                delimiter: $formatConfig['delimiter'] ?? null,
                quoteChar: $formatConfig['quote_char'] ?? null,
                hasHeader: $formatConfig['has_header'] ?? true,
                encoding: $formatConfig['encoding'] ?? 'UTF-8'
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Convert source_schema from mapping JSON to SourceSchema value object.
     */
    private function convertSourceSchemaFromMapping(array $sourceSchemaData): ?SourceSchema
    {
        try {
            $columns = [];
            $totalRows = $sourceSchemaData['total_rows'] ?? 0;

            foreach ($sourceSchemaData['columns'] ?? [] as $columnName => $columnData) {
                $type = ColumnType::tryFrom($columnData['type'] ?? 'string') ?? ColumnType::String;

                $columns[$columnName] = new ColumnMetadata(
                    name: $columnData['name'] ?? $columnName,
                    type: $type,
                    nullCount: $columnData['null_count'] ?? 0,
                    uniqueCount: $columnData['unique_count'] ?? 0,
                    min: $columnData['min'] ?? null,
                    max: $columnData['max'] ?? null,
                    examples: $columnData['examples'] ?? []
                );
            }

            return new SourceSchema(
                columns: $columns,
                totalRows: $totalRows
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    // Helper methods for configuration and decisions

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

    /**
     * Normalize sanitizer config from JSON format.
     *
     * Converts JSON format (newline_format as string "lf") to internal format (newline_format as character "\n").
     *
     * @param  array<string, mixed>  $jsonConfig  Sanitizer config from JSON
     * @return array<string, mixed> Normalized sanitizer config
     */
    private function normalizeSanitizerConfigFromJson(array $jsonConfig): array
    {
        $normalized = [];

        // Map JSON keys to internal keys
        $normalized[SanitizerConfigKeys::RemoveBom] = $jsonConfig['remove_bom'] ?? true;
        $normalized[SanitizerConfigKeys::NormalizeNewlines] = $jsonConfig['normalize_newlines'] ?? true;
        $normalized[SanitizerConfigKeys::RemoveControlChars] = $jsonConfig['remove_control_chars'] ?? true;
        $normalized[SanitizerConfigKeys::HandleTruncatedEof] = $jsonConfig['handle_truncated_eof'] ?? true;

        // Convert newline_format from string ("lf") to character ("\n")
        $newlineFormat = $jsonConfig['newline_format'] ?? 'lf';
        if (is_string($newlineFormat) && strlen($newlineFormat) > 2) {
            $format = NewlineFormat::tryFrom(strtolower($newlineFormat)) ?? NewlineFormat::Lf;
            $normalized[SanitizerConfigKeys::NewlineFormat] = $format->getCharacter();
        } else {
            $normalized[SanitizerConfigKeys::NewlineFormat] = $newlineFormat;
        }

        return $normalized;
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

        foreach ($reader as $row) {
            $rows[] = $row;
            if (count($rows) >= $maxRows) {
                break;
            }
        }

        return [
            'rows' => $rows,
            'count' => count($rows),
        ];
    }
}
