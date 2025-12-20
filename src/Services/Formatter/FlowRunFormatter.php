<?php

namespace InFlow\Services\Formatter;

use InFlow\ValueObjects\Flow\FlowRun;
use InFlow\ViewModels\FlowRunViewModel;

/**
 * Formatter for flow run results
 */
readonly class FlowRunFormatter
{
    public function format(FlowRun $run): FlowRunViewModel
    {
        $statusIcon = match ($run->status->value) {
            'completed' => '✓',
            'partially_completed' => '⚠',
            'failed' => '✗',
            default => '○',
        };

        $duration = $run->getDuration();
        $successRate = $run->totalRows > 0
            ? round(($run->importedRows / $run->totalRows) * 100, 1)
            : null;

        $completionMessage = match ($run->status->value) {
            'completed' => 'Processing completed successfully.',
            'partially_completed' => 'Processing completed with errors.',
            'failed' => 'Processing failed.',
            default => 'Processing finished.',
        };

        return new FlowRunViewModel(
            title: 'Flow Execution Results',
            status: $run->status->value,
            statusIcon: $statusIcon,
            importedRows: $run->importedRows,
            skippedRows: $run->skippedRows,
            errorCount: $run->errorCount,
            totalRows: $run->totalRows,
            duration: $duration,
            successRate: $successRate,
            errors: $run->errors,
            completionMessage: $completionMessage,
        );
    }
}
