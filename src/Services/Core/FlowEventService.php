<?php

namespace InFlow\Services\Core;

use Illuminate\Support\Facades\Event;
use InFlow\Contracts\SanitizationReportInterface;
use InFlow\Events\FormatDetected;
use InFlow\Events\ProfileCompleted;
use InFlow\Events\RowImported;
use InFlow\Events\RowSkipped;
use InFlow\Events\RunFailed;
use InFlow\Events\SanitizationCompleted;
use InFlow\ValueObjects\DetectedFormat;
use InFlow\ValueObjects\QualityReport;
use InFlow\ValueObjects\SourceSchema;

/**
 * Service for emitting flow execution events.
 *
 * Handles presentation logic: event emission for flow execution steps.
 * Business logic is handled by other services.
 */
readonly class FlowEventService
{
    /**
     * Emit sanitization completed event.
     *
     * Presentation: notifies listeners about sanitization completion.
     *
     * @param  string  $sourceFile  The source file path
     * @param  SanitizationReportInterface  $report  The sanitization report
     */
    public function emitSanitizationCompleted(string $sourceFile, SanitizationReportInterface $report): void
    {
        Event::dispatch(new SanitizationCompleted($sourceFile, $report));
    }

    /**
     * Emit format detected event.
     *
     * Presentation: notifies listeners about format detection.
     *
     * @param  string  $sourceFile  The source file path
     * @param  DetectedFormat  $format  The detected format
     */
    public function emitFormatDetected(string $sourceFile, DetectedFormat $format): void
    {
        Event::dispatch(new FormatDetected($sourceFile, $format));
    }

    /**
     * Emit profile completed event.
     *
     * Presentation: notifies listeners about profiling completion.
     *
     * @param  string  $sourceFile  The source file path
     * @param  SourceSchema  $schema  The source schema
     * @param  QualityReport  $qualityReport  The quality report
     */
    public function emitProfileCompleted(string $sourceFile, SourceSchema $schema, QualityReport $qualityReport): void
    {
        Event::dispatch(new ProfileCompleted($sourceFile, $schema, $qualityReport));
    }

    /**
     * Emit row imported event.
     *
     * Presentation: notifies listeners about row import.
     *
     * @param  int  $rowNumber  The row number
     * @param  array<string, mixed>  $rowData  The row data
     * @param  \Illuminate\Database\Eloquent\Model  $model  The imported model
     */
    public function emitRowImported(int $rowNumber, array $rowData, \Illuminate\Database\Eloquent\Model $model): void
    {
        Event::dispatch(new RowImported($rowNumber, $rowData, $model));
    }

    /**
     * Emit row skipped event.
     *
     * Presentation: notifies listeners about row skip.
     *
     * @param  int  $rowNumber  The row number
     * @param  array<string, mixed>  $rowData  The row data
     * @param  string  $reason  The skip reason
     * @param  array<string, mixed>  $metadata  Additional metadata
     */
    public function emitRowSkipped(int $rowNumber, array $rowData, string $reason, array $metadata = []): void
    {
        Event::dispatch(new RowSkipped($rowNumber, $rowData, $reason, $metadata));
    }

    /**
     * Emit run failed event.
     *
     * Presentation: notifies listeners about flow execution failure.
     *
     * @param  string  $sourceFile  The source file path
     * @param  string  $message  The error message
     * @param  \Throwable|null  $exception  The exception if any
     */
    public function emitRunFailed(string $sourceFile, string $message, ?\Throwable $exception = null): void
    {
        Event::dispatch(new RunFailed($sourceFile, $message, $exception));
    }
}
