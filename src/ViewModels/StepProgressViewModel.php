<?php

namespace InFlow\ViewModels;

/**
 * View Model for step progress display
 */
readonly class StepProgressViewModel
{
    public function __construct(
        public int $currentStep,
        public int $totalSteps,
        public string $stepDescription,
    ) {}
}
