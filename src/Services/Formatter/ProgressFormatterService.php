<?php

namespace InFlow\Services\Formatter;

use InFlow\Enums\FlowRunStatisticKey;
use InFlow\ValueObjects\FlowRun;

/**
 * Service for formatting progress information for display.
 *
 * Handles the business logic of formatting progress data (numbers, labels, hints)
 * for display. Presentation logic (progress bar, output) is handled by the caller.
 */
readonly class ProgressFormatterService
{
    /**
     * Format progress statistics for display.
     *
     * @param  FlowRun  $run  The flow run with progress data
     * @return array<string, string> Formatted statistics using FlowRunStatisticKey enum values as keys
     */
    public function formatStatistics(FlowRun $run): array
    {
        return [
            FlowRunStatisticKey::Imported->value => number_format($run->importedRows),
            FlowRunStatisticKey::Skipped->value => number_format($run->skippedRows),
            FlowRunStatisticKey::Errors->value => number_format($run->errorCount),
            FlowRunStatisticKey::Progress->value => $this->formatProgress($run),
        ];
    }

    /**
     * Format progress percentage.
     *
     * @param  FlowRun  $run  The flow run
     * @return string Formatted progress percentage
     */
    public function formatProgress(FlowRun $run): string
    {
        if ($run->totalRows > 0) {
            return number_format($run->progress, 1);
        }

        return '0.0';
    }

    /**
     * Build progress hint text with formatted counters.
     *
     * @param  FlowRun  $run  The flow run
     * @return string Formatted hint text
     */
    public function buildProgressHint(FlowRun $run): string
    {
        $stats = $this->formatStatistics($run);

        return "Imported: <fg=green>{$stats[FlowRunStatisticKey::Imported->value]}</> | Skipped: <fg=yellow>{$stats[FlowRunStatisticKey::Skipped->value]}</> | Errors: <fg=red>{$stats[FlowRunStatisticKey::Errors->value]}</>";
    }

    /**
     * Build progress label with hint.
     *
     * @param  FlowRun  $run  The flow run
     * @param  string  $baseLabel  The base label text
     * @return string Formatted label with hint
     */
    public function buildProgressLabel(FlowRun $run, string $baseLabel): string
    {
        $hint = $this->buildProgressHint($run);

        return "{$baseLabel} | {$hint}";
    }

    /**
     * Build counter text for display below progress bar.
     *
     * @param  FlowRun  $run  The flow run
     * @return string Formatted counter text
     */
    public function buildCounterText(FlowRun $run): string
    {
        $stats = $this->formatStatistics($run);

        return "  <fg=cyan>→</> Imported: <fg=green>{$stats[FlowRunStatisticKey::Imported->value]}</> | Skipped: <fg=yellow>{$stats[FlowRunStatisticKey::Skipped->value]}</> | Errors: <fg=red>{$stats[FlowRunStatisticKey::Errors->value]}</> | Progress: <fg=yellow>{$stats[FlowRunStatisticKey::Progress->value]}%</>";
    }

    /**
     * Build fallback progress text (when progress bar not initialized).
     *
     * @param  FlowRun  $run  The flow run
     * @return string Formatted fallback text
     */
    public function buildFallbackProgressText(FlowRun $run): string
    {
        $stats = $this->formatStatistics($run);

        return "  Progress: <fg=yellow>{$stats[FlowRunStatisticKey::Progress->value]}%</> | Imported: <fg=green>{$stats[FlowRunStatisticKey::Imported->value]}</> | Skipped: <fg=yellow>{$stats[FlowRunStatisticKey::Skipped->value]}</> | Errors: <fg=red>{$stats[FlowRunStatisticKey::Errors->value]}</>";
    }

    /**
     * Calculate current progress step (imported + skipped).
     *
     * @param  FlowRun  $run  The flow run
     * @return int Current progress step
     */
    public function calculateCurrentStep(FlowRun $run): int
    {
        return $run->importedRows + $run->skippedRows;
    }

    /**
     * Calculate steps to advance.
     *
     * @param  FlowRun  $run  The flow run
     * @param  int  $currentStep  Current tracked step
     * @return int Steps to advance
     */
    public function calculateStepsToAdvance(FlowRun $run, int $currentStep): int
    {
        $targetStep = $this->calculateCurrentStep($run);

        return max(0, $targetStep - $currentStep);
    }
}
