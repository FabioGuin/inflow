<?php

namespace InFlow\Executors;

use Illuminate\Validation\ValidationException;
use InFlow\Loaders\EloquentLoader;
use InFlow\Mappings\MappingValidator;
use InFlow\Profilers\Profiler;
use InFlow\Services\Loading\PivotSyncService;
use InFlow\Services\Mapping\MappingDependencyValidator;
use InFlow\Readers\CsvReader;
use InFlow\Readers\ExcelReader;
use InFlow\Readers\JsonLinesReader;
use InFlow\Readers\XmlReader;
use InFlow\Services\Core\FlowExecutionService;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\File\DetectedFormat;
use InFlow\ValueObjects\Flow\Flow;
use InFlow\ValueObjects\Flow\FlowRun;
use InFlow\ValueObjects\Mapping\ModelMapping;
use InFlow\ValueObjects\Data\Row;

/**
 * Executor for orchestrating the complete ETL pipeline
 *
 * FlowExecutor orchestrates the end-to-end ETL process:
 * FileSource → RawSanitizer → FormatDetector → TabularReader → Profiler → Mapping → Transform → Load
 *
 * Features:
 * - Chunking for large files
 * - Error handling and recovery
 * - Progress tracking via FlowRun
 * - Structured logging
 */
class FlowExecutor
{
    /**
     * @var callable|null
     */
    private $progressCallback;

    /**
     * Optional callback invoked when a row-level error occurs and the executor would normally continue.
     *
     * Signature: fn(\Throwable $e, Row $row, Flow $flow, int $rowNumber): string
     * Returns one of: 'continue' | 'stop' | 'stop_on_error' | 'continue_silent'
     *
     * @var callable|null
     */
    private $errorDecisionCallback;

    private bool $forceStopOnError = false;

    public function __construct(
        private readonly FlowExecutionService $flowExecutionService,
        private readonly Profiler $profiler,
        private readonly EloquentLoader $loader,
        private readonly MappingValidator $mappingValidator,
        private readonly MappingDependencyValidator $dependencyValidator,
        private readonly PivotSyncService $pivotSyncService,
        ?callable $progressCallback = null,
        ?callable $errorDecisionCallback = null
    ) {
        $this->progressCallback = $progressCallback;
        $this->errorDecisionCallback = $errorDecisionCallback;
    }

    /**
     * Execute a Flow on a source file
     *
     * @param  Flow  $flow  The flow configuration to execute
     * @param  string  $sourceFile  Path to the source file
     * @return FlowRun The execution result with statistics and status
     */
    public function execute(Flow $flow, string $sourceFile): FlowRun
    {
        // Validate flow configuration
        $validationResult = $this->validateFlow($flow, $sourceFile);
        if ($validationResult !== null) {
            return $validationResult;
        }

        // Create initial FlowRun
        $run = FlowRun::create($sourceFile)->start();
        $tempFile = null;

        try {
            // Step 1: Prepare source file (load and sanitize if needed)
            // array destructuring
            [$source, $tempFile] = $this->prepareSourceFile($flow, $sourceFile, $run);
            if ($source === null) {
                return $run;
            }

            $run = $run->updateProgress(0, 0, 0);

            // Step 2: Setup reader (detect format, create reader, count rows)
            [$reader, $format, $run] = $this->setupReader($source, $flow, $run, $sourceFile);
            if ($reader === null) {
                return $run;
            }

            // Step 3: Profile data (optional, only if no mapping provided)
            $run = $this->executeProfiling($flow, $reader, $run, $sourceFile);

            // Step 4: Load data (if mapping is provided)
            $run = $this->executeDataLoading($flow, $reader, $run);

            // Complete the run
            $run = $run->complete();

            $this->cleanupTempFile($tempFile);

            return $run;
        } catch (\Throwable $e) {
            return $this->handleExecutionError($e, $flow, $sourceFile, $run, $tempFile);
        }
    }

    /**
     * Validate flow configuration.
     *
     * Business logic: validates flow configuration.
     * Presentation: emits event on validation failure.
     *
     * @param  Flow  $flow  The flow to validate
     * @param  string  $sourceFile  The source file path
     * @return FlowRun|null Returns failed FlowRun if validation fails, null otherwise
     */
    private function validateFlow(Flow $flow, string $sourceFile): ?FlowRun
    {
        // Business logic: validate flow
        $errors = $flow->validate();
        if (! empty($errors)) {
            $run = FlowRun::create($sourceFile)->fail(
                'Flow validation failed: '.implode(', ', $errors)
            );

            return $run;
        }

        // Business logic: validate mapping dependencies if mapping exists
        if ($flow->mapping !== null) {
            $dependencyErrors = $this->dependencyValidator->validate($flow->mapping);
            if (! empty($dependencyErrors)) {
                $run = FlowRun::create($sourceFile)->fail(
                    'Mapping dependency validation failed: '.implode(', ', $dependencyErrors)
                );

                return $run;
            }
        }

        return null;
    }

    /**
     * Prepare source file (load and sanitize if needed).
     *
     * Business logic: delegates to FlowExecutionService.
     * Presentation: emits sanitization event if performed.
     *
     * @param  Flow  $flow  The flow configuration
     * @param  string  $sourceFile  The source file path
     * @param  FlowRun  $run  The current flow run
     * @return array{0: FileSource|null, 1: string|null} Tuple of [source, tempFile]
     */
    private function prepareSourceFile(Flow $flow, string $sourceFile, FlowRun $run): array
    {
        try {
            // Business logic: prepare source file (sanitize if needed)
            [$source, $tempFile, $report] = $this->flowExecutionService->prepareSourceFile($sourceFile, $flow->sanitizerConfig);

            return [$source, $tempFile];
        } catch (\RuntimeException $e) {
            \inflow_report($e, 'error', ['operation' => 'loadAndSanitize', 'source' => $sourceFile]);
            $run->fail($e->getMessage());

            return [null, null];
        }
    }

    /**
     * Setup reader (detect format, create reader, count rows).
     *
     * Business logic: delegates to FlowExecutionService.
     * Presentation: emits format detected event.
     *
     * @param  FileSource  $source  The file source
     * @param  Flow  $flow  The flow configuration
     * @param  FlowRun  $run  The current flow run
     * @param  string  $sourceFile  The source file path
     * @return array{0: JsonLinesReader|CsvReader|ExcelReader|XmlReader|null, 1: DetectedFormat, 2: FlowRun} Tuple of [reader, format, updated run]
     */
    private function setupReader(FileSource $source, Flow $flow, FlowRun $run, string $sourceFile): array
    {
        // Business logic: detect format
        $format = $this->flowExecutionService->detectFormat($source, $flow->formatConfig);

        // Business logic: create reader
        $reader = $this->flowExecutionService->createReader($source, $format);
        if ($reader === null) {
            $run = $run->fail('Unsupported file format: '.$format->type->value);

            return [null, $format, $run];
        }

        // Business logic: count total rows for progress tracking
        $totalRows = $this->flowExecutionService->countRows($reader);

        // Business logic: update run with format and total rows
        $run = $run->withFormat($format, $totalRows);

        return [$reader, $format, $run];
    }

    /**
     * Execute profiling if needed (only if no mapping is provided).
     *
     * Business logic: delegates to Profiler.
     * Presentation: emits profile completed event.
     *
     * @param  Flow  $flow  The flow configuration
     * @param  CsvReader|ExcelReader|JsonLinesReader  $reader  The reader
     * @param  FlowRun  $run  The current flow run
     * @param  string  $sourceFile  The source file path
     * @return FlowRun Updated flow run
     */
    private function executeProfiling(Flow $flow, JsonLinesReader|CsvReader|ExcelReader|XmlReader $reader, FlowRun $run, string $sourceFile): FlowRun
    {
        if ($flow->mapping !== null) {
            return $run;
        }

        // Business logic: profile data
        $reader->rewind();
        $profileResult = $this->profiler->profile($reader);
        $schema = $profileResult['schema'];
        $qualityReport = $profileResult['quality_report'];

        // Business logic: update run with schema
        return $run->withSchema($schema);
    }

    /**
     * Execute data loading if mapping is provided.
     *
     * @param  Flow  $flow  The flow configuration
     * @param  CsvReader|ExcelReader|JsonLinesReader  $reader  The reader
     * @param  FlowRun  $run  The current flow run
     * @return FlowRun Updated flow run
     */
    private function executeDataLoading(Flow $flow, JsonLinesReader|CsvReader|ExcelReader|XmlReader $reader, FlowRun $run): FlowRun
    {
        if ($flow->mapping === null) {
            return $run;
        }

        return $this->loadData($reader, $flow, $run);
    }

    /**
     * Handle execution error.
     *
     * Business logic: marks run as failed.
     * Presentation: logs error and emits event.
     *
     * @param  \Throwable  $e  The exception
     * @param  Flow  $flow  The flow configuration
     * @param  string  $sourceFile  The source file path
     * @param  FlowRun  $run  The current flow run
     * @param  string|null  $tempFile  The temporary file path if created
     * @return FlowRun Failed flow run
     */
    private function handleExecutionError(\Throwable $e, Flow $flow, string $sourceFile, FlowRun $run, ?string $tempFile): FlowRun
    {
        // Presentation: log error
        \inflow_report($e, 'error', [
            'operation' => 'flowExecution',
            'flow' => $flow->name,
        ]);

        // Business logic: mark run as failed
        $run = $run->fail($e->getMessage(), $e);

        $this->cleanupTempFile($tempFile);

        return $run;
    }

    /**
     * Cleanup temporary file if created.
     *
     * @param  string|null  $tempFile  The temporary file path
     */
    private function cleanupTempFile(?string $tempFile): void
    {
        if ($tempFile !== null && file_exists($tempFile)) {
            @unlink($tempFile);
        }
    }

    /**
     * Load data into models (row by row for real-time progress)
     */
    private function loadData($reader, Flow $flow, FlowRun $run): FlowRun
    {
        $reader->rewind();

        $executionStatistics = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'rowNumber' => 0,
            'emptyRows' => [],
            'truncatedFieldsDetails' => [],
        ];

        foreach ($reader as $rowData) {
            $executionStatistics['rowNumber']++;
            $row = $this->createRowFromData($rowData, $executionStatistics['rowNumber']);

            // Handle empty rows
            if ($row->isEmpty()) {
                $this->handleEmptyRow($row, $executionStatistics);

                continue;
            }

            // Process row
            $result = $this->processRow($row, $flow, $executionStatistics, $run);
            if ($result['shouldStop']) {
                return $result['run'];
            }

            $run = $result['run'];

            // Update progress if needed
            if ($this->shouldUpdateProgress($run, $executionStatistics['rowNumber'])) {
                $run = $run->updateProgress($executionStatistics['imported'], $executionStatistics['skipped'], $executionStatistics['errors']);
                $this->updateProgress($run);
            }
        }

        // Final progress update and add warnings
        $run = $run->updateProgress($executionStatistics['imported'], $executionStatistics['skipped'], $executionStatistics['errors']);
        $run = $this->addWarningsToRun($run, $executionStatistics);
        $this->updateProgress($run);

        return $run;
    }

    /**
     * Create Row object from row data.
     *
     * @param  array|Row  $rowData  The row data
     * @param  int  $rowNumber  The row number
     * @return Row The Row object
     */
    private function createRowFromData(Row|array $rowData, int $rowNumber): Row
    {
        return $rowData instanceof Row ? $rowData : new Row($rowData, $rowNumber);
    }

    /**
     * Handle empty row.
     *
     * Business logic: tracks empty row in statistics.
     * Presentation: emits row skipped event.
     *
     * @param  Row  $row  The empty row
     * @param  array<string, mixed>  $executionStatistics  The statistics array (by reference)
     */
    private function handleEmptyRow(Row $row, array &$executionStatistics): void
    {
        // Business logic: track empty row
        $executionStatistics['skipped']++;
        $rowId = $this->extractRowId($row);
        $executionStatistics['emptyRows'][] = [
            'row_number' => $executionStatistics['rowNumber'],
            'id' => $rowId,
        ];

    }

    /**
     * Process a single row with a specific model mapping.
     *
     * @param  Row  $row  The row to process
     * @param  ModelMapping  $modelMapping  The model mapping to use
     * @param  Flow  $flow  The flow configuration
     * @param  array<string, mixed>  $executionStatistics  The statistics array (by reference)
     * @param  FlowRun  $run  The current flow run
     * @return array{run: FlowRun, shouldStop: bool} Processing result
     */
    private function processModelMappingForRow(Row $row, ModelMapping $modelMapping, Flow $flow, array &$executionStatistics, FlowRun $run): array
    {
        $stopOnError = $flow->shouldStopOnError() || $this->forceStopOnError;

        try {
            // Handle pivot_sync type
            if ($modelMapping->type === 'pivot_sync') {
                $this->processPivotSync($row, $modelMapping, $executionStatistics);
            } else {
                // Standard model mapping
                $this->processModelMapping($row, $modelMapping, $executionStatistics);
            }

            return ['run' => $run, 'shouldStop' => false];
        } catch (ValidationException $e) {
            return $this->handleValidationError($e, $row, $flow, $executionStatistics, $run, $stopOnError);
        } catch (\Exception $e) {
            return $this->handleProcessingError($e, $row, $flow, $executionStatistics, $run, $stopOnError);
        }
    }

    /**
     * Process a single model mapping.
     *
     * Business logic: validates row, then loads model using EloquentLoader.
     * Presentation: emits row imported/skipped events.
     *
     * @param  Row  $row  The row to process
     * @param  ModelMapping  $modelMapping  The model mapping
     * @param  array<string, mixed>  $executionStatistics  The statistics array (by reference)
     *
     * @throws ValidationException If validation fails
     */
    private function processModelMapping(Row $row, ModelMapping $modelMapping, array &$executionStatistics): void
    {
        // Business logic: validate row before loading
        $validationResult = $this->mappingValidator->validateRow($row, $modelMapping);
        if (! $validationResult['passes']) {
            throw ValidationException::withMessages($validationResult['errors']);
        }

        // Business logic: reset truncated fields for this row
        $this->loader->resetTruncatedFields();

        // Business logic: load model
        $model = $this->loader->load($row, $modelMapping);

        // Business logic: collect truncated fields details for this row
        $this->collectTruncatedFields($row, $executionStatistics);

        // Business logic: update statistics
        if ($model === null) {
            // Model was skipped (duplicate with 'skip' strategy)
            $executionStatistics['skipped']++;
        } else {
            $executionStatistics['imported']++;
        }
    }

    /**
     * Process pivot sync for a row.
     *
     * @param  Row  $row  The row to process
     * @param  ModelMapping  $modelMapping  The pivot_sync mapping
     * @param  array<string, mixed>  $executionStatistics  The statistics array (by reference)
     */
    private function processPivotSync(Row $row, ModelMapping $modelMapping, array &$executionStatistics): void
    {
        try {
            $this->pivotSyncService->sync($row, $modelMapping);
            $executionStatistics['imported']++;
        } catch (\Exception $e) {
            $executionStatistics['errors']++;
            throw $e;
        }
    }

    /**
     * Collect truncated fields details for a row.
     *
     * @param  Row  $row  The row
     * @param  array<string, mixed>  $executionStatistics  The statistics array (by reference)
     */
    private function collectTruncatedFields(Row $row, array &$executionStatistics): void
    {
        $truncatedFields = $this->loader->getTruncatedFields();
        if (empty($truncatedFields)) {
            return;
        }

        $rowId = $this->extractRowId($row);
        foreach ($truncatedFields as $field) {
            $executionStatistics['truncatedFieldsDetails'][] = [
                'row_number' => $executionStatistics['rowNumber'],
                'id' => $rowId,
                'field' => $field['field'],
                'original_length' => $field['original_length'],
                'max_length' => $field['max_length'],
            ];
        }
    }

    /**
     * Handle validation error.
     *
     * Business logic: updates statistics and adds error to run.
     * Presentation: logs error and emits row skipped event.
     *
     * @param  ValidationException  $e  The validation exception
     * @param  Row  $row  The row that failed
     * @param  Flow  $flow  The flow configuration
     * @param  array<string, mixed>  $executionStatistics  The statistics array (by reference)
     * @param  FlowRun  $run  The current flow run
     * @param  bool  $stopOnError  Whether to stop on error
     * @return array{run: FlowRun, shouldStop: bool} Processing result
     */
    private function handleValidationError(ValidationException $e, Row $row, Flow $flow, array &$executionStatistics, FlowRun $run, bool $stopOnError): array
    {
        $decision = $this->decideOnRowError($e, $row, $flow, $executionStatistics['rowNumber']);

        // Presentation: log validation error
        \inflow_report($e, 'info', [
            'operation' => 'validateRow',
            'rowNumber' => $executionStatistics['rowNumber'],
            'flow' => $flow->name,
            'errors' => $e->errors(),
        ]);

        // Business logic: update statistics
        $executionStatistics['skipped']++;

        // Business logic: add error to run with validation details
        $run = $run->addError(
            'Validation failed for row '.$executionStatistics['rowNumber'],
            $executionStatistics['rowNumber'],
            [
                'errors' => $e->errors(),
                'data' => $row->toArray(),
                'validation_errors' => $e->errors(),
            ]
        );

        if ($decision === 'stop') {
            $run = $run->fail('Stopped by user at row '.$executionStatistics['rowNumber'].': '.$e->getMessage(), $e);

            return ['run' => $run, 'shouldStop' => true];
        }

        if ($decision === 'stop_on_error') {
            $this->forceStopOnError = true;
        }

        if ($stopOnError) {
            $run = $run->fail('Stopped on validation error at row '.$executionStatistics['rowNumber']);

            return ['run' => $run, 'shouldStop' => true];
        }

        return ['run' => $run, 'shouldStop' => false];
    }

    /**
     * Handle processing error.
     *
     * Business logic: updates statistics and adds error to run.
     * Presentation: logs error and emits row skipped event.
     *
     * @param  \Exception  $e  The exception
     * @param  Row  $row  The row that failed
     * @param  Flow  $flow  The flow configuration
     * @param  array<string, mixed>  $executionStatistics  The statistics array (by reference)
     * @param  FlowRun  $run  The current flow run
     * @param  bool  $stopOnError  Whether to stop on error
     * @return array{run: FlowRun, shouldStop: bool} Processing result
     */
    private function handleProcessingError(\Exception $e, Row $row, Flow $flow, array &$executionStatistics, FlowRun $run, bool $stopOnError): array
    {
        $decision = $this->decideOnRowError($e, $row, $flow, $executionStatistics['rowNumber']);

        // Presentation: log processing error
        \inflow_report($e, 'warning', [
            'operation' => 'processRow',
            'rowNumber' => $executionStatistics['rowNumber'],
            'flow' => $flow->name,
        ]);

        // Business logic: update statistics
        $executionStatistics['errors']++;

        // Business logic: add error to run
        $run = $run->addError($e->getMessage(), $executionStatistics['rowNumber'], ['exception' => $e->getMessage()]);

        if ($decision === 'stop') {
            $run = $run->fail('Stopped by user at row '.$executionStatistics['rowNumber'].': '.$e->getMessage(), $e);

            return ['run' => $run, 'shouldStop' => true];
        }

        if ($decision === 'stop_on_error') {
            $this->forceStopOnError = true;
        }

        if ($stopOnError) {
            $run = $run->fail('Stopped on error at row '.$executionStatistics['rowNumber'].': '.$e->getMessage(), $e);

            return ['run' => $run, 'shouldStop' => true];
        }

        return ['run' => $run, 'shouldStop' => false];
    }

    private function decideOnRowError(\Throwable $e, Row $row, Flow $flow, int $rowNumber): string
    {
        if ($this->errorDecisionCallback === null) {
            return 'continue';
        }

        try {
            $decision = ($this->errorDecisionCallback)($e, $row, $flow, $rowNumber);

            return is_string($decision) ? $decision : 'continue';
        } catch (\Throwable $callbackError) {
            \inflow_report($callbackError, 'debug', [
                'operation' => 'errorDecisionCallback',
                'flow' => $flow->name,
                'rowNumber' => $rowNumber,
            ]);

            return 'continue';
        }
    }

    /**
     * Check if progress should be updated.
     *
     * @param  FlowRun  $run  The current flow run
     * @param  int  $rowNumber  The current row number
     * @return bool True if progress should be updated
     */
    private function shouldUpdateProgress(FlowRun $run, int $rowNumber): bool
    {
        $updateFrequency = $run->totalRows > 10000 ? 10 : 1;

        return $rowNumber % $updateFrequency === 0;
    }

    /**
     * Add warnings to flow run (empty rows and truncated fields).
     *
     * @param  FlowRun  $run  The current flow run
     * @param  array<string, mixed>  $executionStatistics  The statistics array
     * @return FlowRun Updated flow run with warnings
     */
    private function addWarningsToRun(FlowRun $run, array $executionStatistics): FlowRun
    {
        // Add warnings for empty rows
        if (count($executionStatistics['emptyRows']) > 0) {
            $run = $this->addEmptyRowsWarning($run, $executionStatistics['emptyRows']);
        }

        // Add warnings for truncated fields
        if (count($executionStatistics['truncatedFieldsDetails']) > 0) {
            $run = $this->addTruncatedFieldsWarning($run, $executionStatistics['truncatedFieldsDetails']);
        }

        return $run;
    }

    /**
     * Add warning for empty rows.
     *
     * Business logic: adds warning to run.
     *
     * @param  FlowRun  $run  The current flow run
     * @param  array<int, array{row_number: int, id: string|null}>  $emptyRows  The empty rows
     * @return FlowRun Updated flow run
     */
    private function addEmptyRowsWarning(FlowRun $run, array $emptyRows): FlowRun
    {
        $emptyRowsCount = count($emptyRows);
        $emptyRowsList = $this->formatRowsList($emptyRows, 10);
        $message = "{$emptyRowsCount} empty row(s) were skipped during import";
        if (! empty($emptyRowsList)) {
            $message .= ": {$emptyRowsList}";
        }

        return $run->addWarning($message, [
            'empty_rows_count' => $emptyRowsCount,
            'empty_rows' => $emptyRows,
        ]);
    }

    /**
     * Add warning for truncated fields.
     *
     * Business logic: adds warning to run.
     *
     * @param  FlowRun  $run  The current flow run
     * @param  array<int, array{row_number: int, id: string|null, field: string, original_length: int, max_length: int}>  $truncatedFieldsDetails  The truncated fields details
     * @return FlowRun Updated flow run
     */
    private function addTruncatedFieldsWarning(FlowRun $run, array $truncatedFieldsDetails): FlowRun
    {
        $truncatedCount = count($truncatedFieldsDetails);
        $truncatedList = $this->formatTruncatedFieldsList($truncatedFieldsDetails, 10);
        $message = "{$truncatedCount} field(s) were truncated because they exceeded column maximum length";
        if (! empty($truncatedList)) {
            $message .= ": {$truncatedList}";
        }

        return $run->addWarning($message, [
            'truncated_fields_count' => $truncatedCount,
            'truncated_fields' => $truncatedFieldsDetails,
        ]);
    }

    /**
     * Extract row ID from row data (tries common ID column names)
     */
    private function extractRowId(Row $row): ?string
    {
        $idColumns = ['id', 'ID', 'Id', 'row_id', 'rowId', 'external_id', 'externalId'];

        foreach ($idColumns as $column) {
            if ($row->has($column)) {
                $id = $row->get($column);
                if ($id !== null && $id !== '') {
                    return (string) $id;
                }
            }
        }

        return null;
    }

    /**
     * Update progress via callback if provided
     */
    private function updateProgress(FlowRun $run): void
    {
        if ($this->progressCallback !== null) {
            call_user_func($this->progressCallback, $run);
        }
    }

    /**
     * Format list of rows for display (e.g., "Row 1 (ID: 123), Row 5 (ID: 456)").
     */
    private function formatRowsList(array $rows, int $maxItems = 10): string
    {
        $items = [];
        $displayRows = array_slice($rows, 0, $maxItems);

        foreach ($displayRows as $row) {
            $rowNum = $row['row_number'];
            $id = $row['id'] ?? null;

            if ($id !== null) {
                $items[] = "Row {$rowNum} (ID: {$id})";
            } else {
                $items[] = "Row {$rowNum}";
            }
        }

        $result = implode(', ', $items);

        if (count($rows) > $maxItems) {
            $remaining = count($rows) - $maxItems;
            $result .= " and {$remaining} more";
        }

        return $result;
    }

    /**
     * Format list of truncated fields for display.
     */
    private function formatTruncatedFieldsList(array $truncatedFields, int $maxItems = 10): string
    {
        $items = [];
        $displayFields = array_slice($truncatedFields, 0, $maxItems);

        foreach ($displayFields as $field) {
            $rowNum = $field['row_number'];
            $fieldName = $field['field'];
            $id = $field['id'] ?? null;
            $originalLength = $field['original_length'];
            $maxLength = $field['max_length'];

            $rowInfo = $id !== null ? "Row {$rowNum} (ID: {$id})" : "Row {$rowNum}";
            $items[] = "{$rowInfo}, field '{$fieldName}' ({$originalLength} → {$maxLength} chars)";
        }

        $result = implode('; ', $items);

        if (count($truncatedFields) > $maxItems) {
            $remaining = count($truncatedFields) - $maxItems;
            $result .= " and {$remaining} more";
        }

        return $result;
    }
}
