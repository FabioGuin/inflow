<?php

namespace InFlow\Services\Formatter;

use InFlow\Enums\FlowRunStatisticKey;
use InFlow\Enums\FlowRunStatus;
use InFlow\ValueObjects\FlowRun;

/**
 * Service for formatting FlowRun results for display.
 *
 * Handles the business logic of formatting FlowRun data (status colors, statistics,
 * errors, warnings) for display. Presentation logic (output, lines) is handled by the caller.
 */
readonly class FlowRunResultsFormatterService
{
    public function __construct(
        private ProgressFormatterService $progressFormatter
    ) {}

    /**
     * Get color for FlowRun status.
     *
     * @param  FlowRunStatus  $status  The flow run status
     * @return string Color name for ANSI formatting
     */
    public function getStatusColor(FlowRunStatus $status): string
    {
        return match ($status) {
            FlowRunStatus::Completed => 'green',
            FlowRunStatus::PartiallyCompleted => 'yellow',
            FlowRunStatus::Failed => 'red',
            default => 'blue',
        };
    }

    /**
     * Format status line with color.
     *
     * @param  FlowRun  $run  The flow run
     * @return string Formatted status line
     */
    public function formatStatusLine(FlowRun $run): string
    {
        $color = $this->getStatusColor($run->status);

        return "  Status: <fg={$color}>{$run->status->label()}</>";
    }

    /**
     * Format statistics lines.
     *
     * @param  FlowRun  $run  The flow run
     * @return array<string> Array of formatted statistic lines
     */
    public function formatStatisticsLines(FlowRun $run): array
    {
        $stats = $this->progressFormatter->formatStatistics($run);

        return [
            '  Total Rows: <fg=yellow>'.number_format($run->totalRows).'</>',
            '  Imported: <fg=green>'.$stats[FlowRunStatisticKey::Imported->value].'</>',
            '  Skipped: <fg=yellow>'.$stats[FlowRunStatisticKey::Skipped->value].'</>',
            '  Errors: <fg=red>'.$stats[FlowRunStatisticKey::Errors->value].'</>',
            '  Success Rate: <fg=cyan>'.number_format($run->getSuccessRate(), 2).'%</>',
        ];
    }

    /**
     * Format duration line.
     *
     * @param  FlowRun  $run  The flow run
     * @return string|null Formatted duration line, or null if duration not available
     */
    public function formatDurationLine(FlowRun $run): ?string
    {
        $duration = $run->getDuration();

        if ($duration === null) {
            return null;
        }

        return '  Duration: <fg=gray>'.number_format($duration, 3).'s</>';
    }

    /**
     * Format error lines.
     *
     * @param  FlowRun  $run  The flow run
     * @return array<string> Array of formatted error lines
     */
    public function formatErrorLines(FlowRun $run): array
    {
        if ($run->errorCount === 0 || count($run->errors) === 0) {
            return [];
        }

        $lines = [];
        $errorCount = min(5, count($run->errors));

        for ($i = 0; $i < $errorCount; $i++) {
            $error = $run->errors[$i];
            $row = $error['row'] ?? 'N/A';
            $message = $error['message'] ?? 'Unknown error';
            $lines[] = "  <fg=red>Row {$row}:</> {$message}";
        }

        if (count($run->errors) > 5) {
            $remaining = count($run->errors) - 5;
            $lines[] = "  <fg=gray>... and {$remaining} more error(s)</>";
        }

        return $lines;
    }

    /**
     * Format warning lines.
     *
     * @param  FlowRun  $run  The flow run
     * @return array<string> Array of formatted warning lines
     */
    public function formatWarningLines(FlowRun $run): array
    {
        if (count($run->warnings) === 0) {
            return [];
        }

        $lines = [];
        $warnings = array_slice($run->warnings, 0, 5);

        foreach ($warnings as $warning) {
            $message = $warning['message'] ?? 'Unknown warning';
            $lines[] = "  <fg=yellow>•</> {$message}";
        }

        if (count($run->warnings) > 5) {
            $remaining = count($run->warnings) - 5;
            $lines[] = "  <fg=gray>... and {$remaining} more warning(s)</>";
        }

        return $lines;
    }

    /**
     * Check if errors should be displayed.
     *
     * @param  FlowRun  $run  The flow run
     * @return bool True if errors should be displayed
     */
    public function shouldDisplayErrors(FlowRun $run): bool
    {
        return $run->errorCount > 0 && count($run->errors) > 0;
    }

    /**
     * Check if warnings should be displayed.
     *
     * @param  FlowRun  $run  The flow run
     * @return bool True if warnings should be displayed
     */
    public function shouldDisplayWarnings(FlowRun $run): bool
    {
        return count($run->warnings) > 0;
    }

    /**
     * Get status icon for FlowRun status.
     *
     * @param  FlowRunStatus  $status  The flow run status
     * @return string Status icon with ANSI color codes, or empty string
     */
    public function getStatusIcon(FlowRunStatus $status): string
    {
        return match ($status) {
            FlowRunStatus::Completed => '<fg=green>✓</>',
            FlowRunStatus::Failed => '<fg=red>❌</>',
            default => '',
        };
    }

    /**
     * Format status line with icon for summary.
     *
     * @param  FlowRun  $run  The flow run
     * @return string Formatted status line with icon
     */
    public function formatStatusLineWithIcon(FlowRun $run): string
    {
        $icon = $this->getStatusIcon($run->status);
        $iconPrefix = $icon !== '' ? "{$icon} " : '';

        return "  {$iconPrefix}Status: <fg=cyan>{$run->status->label()}</>";
    }

    /**
     * Format summary statistics line (rows imported/skipped).
     *
     * @param  FlowRun  $run  The flow run
     * @return string Formatted summary statistics line
     */
    public function formatSummaryStatisticsLine(FlowRun $run): string
    {
        $stats = $this->progressFormatter->formatStatistics($run);

        return '  Rows: <fg=green>'.$stats[FlowRunStatisticKey::Imported->value].'</> imported, <fg=yellow>'.$stats[FlowRunStatisticKey::Skipped->value].'</> skipped';
    }

    /**
     * Format duration line for summary.
     *
     * @param  float  $duration  The duration in seconds
     * @return string Formatted duration line
     */
    public function formatSummaryDurationLine(float $duration): string
    {
        return '  Duration: <fg=gray>'.number_format($duration, 3).'s</>';
    }

    /**
     * Format success rate line for summary.
     *
     * @param  FlowRun  $run  The flow run
     * @return string Formatted success rate line
     */
    public function formatSummarySuccessRateLine(FlowRun $run): string
    {
        return '  Success Rate: <fg=cyan>'.number_format($run->getSuccessRate(), 2).'%</>';
    }

    /**
     * Format errors line for summary (if errors exist).
     *
     * @param  FlowRun  $run  The flow run
     * @return string|null Formatted errors line, or null if no errors
     */
    public function formatSummaryErrorsLine(FlowRun $run): ?string
    {
        if ($run->errorCount === 0) {
            return null;
        }

        $stats = $this->progressFormatter->formatStatistics($run);

        return '  Errors: <fg=red>'.$stats[FlowRunStatisticKey::Errors->value].'</>';
    }
}
