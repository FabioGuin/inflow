<?php

namespace InFlow\ValueObjects;

use InFlow\Enums\FlowRunStatus;

/**
 * Value Object representing a single execution of a Flow
 *
 * A FlowRun tracks:
 * - Execution state (pending, running, completed, failed, partially_completed)
 * - Statistics (total rows, imported, skipped, errors)
 * - Progress tracking
 * - Errors and warnings
 * - Execution metadata (start time, end time, duration)
 */
readonly class FlowRun
{
    /**
     * @param  FlowRunStatus  $status  Current execution status
     * @param  int  $totalRows  Total rows in source file
     * @param  int  $importedRows  Number of rows successfully imported
     * @param  int  $skippedRows  Number of rows skipped (validation errors, duplicates, etc.)
     * @param  int  $errorCount  Number of errors encountered
     * @param  array<string, mixed>  $errors  Array of error details
     * @param  array<string, mixed>  $warnings  Array of warning messages
     * @param  float  $progress  Progress percentage (0-100)
     * @param  float|null  $startTime  Execution start time (microtime)
     * @param  float|null  $endTime  Execution end time (microtime)
     * @param  string|null  $sourceFile  Path to the source file that was processed
     * @param  array<string, mixed>  $metadata  Additional metadata (e.g., detected format, schema info)
     */
    public function __construct(
        public FlowRunStatus $status,
        public int $totalRows = 0,
        public int $importedRows = 0,
        public int $skippedRows = 0,
        public int $errorCount = 0,
        public array $errors = [],
        public array $warnings = [],
        public float $progress = 0.0,
        public ?float $startTime = null,
        public ?float $endTime = null,
        public ?string $sourceFile = null,
        public array $metadata = []
    ) {}

    /**
     * Create a new FlowRun in pending state
     */
    public static function create(string $sourceFile, int $totalRows = 0): self
    {
        return new self(
            status: FlowRunStatus::Pending,
            totalRows: $totalRows,
            startTime: microtime(true),
            sourceFile: $sourceFile
        );
    }

    /**
     * Mark run as started
     */
    public function start(): self
    {
        return new self(
            status: FlowRunStatus::Running,
            totalRows: $this->totalRows,
            importedRows: $this->importedRows,
            skippedRows: $this->skippedRows,
            errorCount: $this->errorCount,
            errors: $this->errors,
            warnings: $this->warnings,
            progress: $this->progress,
            startTime: $this->startTime ?? microtime(true),
            endTime: $this->endTime,
            sourceFile: $this->sourceFile,
            metadata: $this->metadata
        );
    }

    /**
     * Update progress
     */
    public function updateProgress(int $imported, int $skipped = 0, int $errors = 0): self
    {
        $total = $this->totalRows;
        $progress = $total > 0 ? (($imported + $skipped + $errors) / $total) * 100 : 0.0;

        return new self(
            status: $this->status,
            totalRows: $this->totalRows,
            importedRows: $imported,
            skippedRows: $skipped,
            errorCount: $errors,
            errors: $this->errors,
            warnings: $this->warnings,
            progress: min(100.0, $progress),
            startTime: $this->startTime,
            endTime: $this->endTime,
            sourceFile: $this->sourceFile,
            metadata: $this->metadata
        );
    }

    /**
     * Add an error
     */
    public function addError(string $message, ?int $rowNumber = null, array $context = []): self
    {
        $errors = $this->errors;
        $errors[] = [
            'message' => $message,
            'row' => $rowNumber,
            'context' => $context,
            'timestamp' => microtime(true),
        ];

        return new self(
            status: $this->status,
            totalRows: $this->totalRows,
            importedRows: $this->importedRows,
            skippedRows: $this->skippedRows,
            errorCount: count($errors),
            errors: $errors,
            warnings: $this->warnings,
            progress: $this->progress,
            startTime: $this->startTime,
            endTime: $this->endTime,
            sourceFile: $this->sourceFile,
            metadata: $this->metadata
        );
    }

    /**
     * Add a warning
     */
    public function addWarning(string $message, array $context = []): self
    {
        $warnings = $this->warnings;
        $warnings[] = [
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true),
        ];

        return new self(
            status: $this->status,
            totalRows: $this->totalRows,
            importedRows: $this->importedRows,
            skippedRows: $this->skippedRows,
            errorCount: $this->errorCount,
            errors: $this->errors,
            warnings: $warnings,
            progress: $this->progress,
            startTime: $this->startTime,
            endTime: $this->endTime,
            sourceFile: $this->sourceFile,
            metadata: $this->metadata
        );
    }

    /**
     * Mark run as completed
     */
    public function complete(): self
    {
        $finalStatus = $this->errorCount > 0 && $this->importedRows > 0
            ? FlowRunStatus::PartiallyCompleted
            : FlowRunStatus::Completed;

        return new self(
            status: $finalStatus,
            totalRows: $this->totalRows,
            importedRows: $this->importedRows,
            skippedRows: $this->skippedRows,
            errorCount: $this->errorCount,
            errors: $this->errors,
            warnings: $this->warnings,
            progress: 100.0,
            startTime: $this->startTime,
            endTime: microtime(true),
            sourceFile: $this->sourceFile,
            metadata: $this->metadata
        );
    }

    /**
     * Mark run as failed
     */
    public function fail(string $message, ?\Throwable $exception = null): self
    {
        $errors = $this->errors;
        $errors[] = [
            'message' => $message,
            'exception' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
            'timestamp' => microtime(true),
        ];

        return new self(
            status: FlowRunStatus::Failed,
            totalRows: $this->totalRows,
            importedRows: $this->importedRows,
            skippedRows: $this->skippedRows,
            errorCount: count($errors),
            errors: $errors,
            warnings: $this->warnings,
            progress: $this->progress,
            startTime: $this->startTime,
            endTime: microtime(true),
            sourceFile: $this->sourceFile,
            metadata: $this->metadata
        );
    }

    /**
     * Get execution duration in seconds
     */
    public function getDuration(): ?float
    {
        if ($this->startTime === null) {
            return null;
        }

        $endTime = $this->endTime ?? microtime(true);

        return round($endTime - $this->startTime, 3);
    }

    /**
     * Get success rate percentage
     */
    public function getSuccessRate(): float
    {
        if ($this->totalRows === 0) {
            return 0.0;
        }

        return round(($this->importedRows / $this->totalRows) * 100, 2);
    }

    /**
     * Convert FlowRun to array for serialization
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'total_rows' => $this->totalRows,
            'imported_rows' => $this->importedRows,
            'skipped_rows' => $this->skippedRows,
            'error_count' => $this->errorCount,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'progress' => $this->progress,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'duration' => $this->getDuration(),
            'success_rate' => $this->getSuccessRate(),
            'source_file' => $this->sourceFile,
            'metadata' => $this->metadata,
        ];
    }
}
