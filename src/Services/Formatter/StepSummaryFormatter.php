<?php

namespace InFlow\Services\Formatter;

use InFlow\ViewModels\StepSummaryViewModel;

/**
 * Formatter for step summary with continue prompt
 */
readonly class StepSummaryFormatter
{
    /**
     * @param  array<string, string>  $summary
     */
    public function format(string $stepName, array $summary, bool $showContinuePrompt = true): StepSummaryViewModel
    {
        return new StepSummaryViewModel(
            stepName: $stepName,
            summary: $summary,
            showContinuePrompt: $showContinuePrompt,
        );
    }
}
