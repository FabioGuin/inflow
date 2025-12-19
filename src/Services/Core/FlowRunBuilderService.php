<?php

namespace InFlow\Services\Core;

use InFlow\ValueObjects\DetectedFormat;
use InFlow\ValueObjects\FlowRun;
use InFlow\ValueObjects\SourceSchema;

/**
 * Service for building and updating FlowRun objects.
 *
 * Handles the business logic of constructing and updating FlowRun instances
 * with metadata (format, schema, etc.). Presentation logic is handled by the caller.
 */
readonly class FlowRunBuilderService
{
    /**
     * Update FlowRun with format and total rows.
     *
     * Business logic: creates new FlowRun with updated metadata.
     *
     * @param  FlowRun  $run  The current flow run
     * @param  DetectedFormat  $format  The detected format
     * @param  int  $totalRows  The total number of rows
     * @return FlowRun Updated flow run
     */
    public function updateWithFormat(FlowRun $run, DetectedFormat $format, int $totalRows): FlowRun
    {
        return new FlowRun(
            status: $run->status,
            totalRows: $totalRows,
            importedRows: $run->importedRows,
            skippedRows: $run->skippedRows,
            errorCount: $run->errorCount,
            errors: $run->errors,
            warnings: $run->warnings,
            progress: $run->progress,
            startTime: $run->startTime,
            endTime: $run->endTime,
            sourceFile: $run->sourceFile,
            metadata: array_merge($run->metadata, [
                'format' => $format->toArray(),
            ])
        );
    }

    /**
     * Update FlowRun with schema.
     *
     * Business logic: creates new FlowRun with schema metadata.
     *
     * @param  FlowRun  $run  The current flow run
     * @param  SourceSchema  $schema  The source schema
     * @return FlowRun Updated flow run
     */
    public function updateWithSchema(FlowRun $run, SourceSchema $schema): FlowRun
    {
        return new FlowRun(
            status: $run->status,
            totalRows: $run->totalRows,
            importedRows: $run->importedRows,
            skippedRows: $run->skippedRows,
            errorCount: $run->errorCount,
            errors: $run->errors,
            warnings: $run->warnings,
            progress: $run->progress,
            startTime: $run->startTime,
            endTime: $run->endTime,
            sourceFile: $run->sourceFile,
            metadata: array_merge($run->metadata, [
                'schema' => $schema->toArray(),
            ])
        );
    }
}
