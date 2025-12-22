<?php

namespace InFlow\ViewModels;

/**
 * View Model for step summary with continue prompt
 */
readonly class StepSummaryViewModel
{
    /**
     * @param  array<string, string>  $summary
     */
    public function __construct(
        public string $stepName,
        public array $summary,
        public bool $showContinuePrompt,
    ) {}
}
