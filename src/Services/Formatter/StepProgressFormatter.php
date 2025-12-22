<?php

namespace InFlow\Services\Formatter;

use InFlow\ViewModels\StepProgressViewModel;

/**
 * Formatter for step progress information
 */
readonly class StepProgressFormatter
{
    public function format(int $currentStep, int $totalSteps, string $description): StepProgressViewModel
    {
        return new StepProgressViewModel(
            currentStep: $currentStep,
            totalSteps: $totalSteps,
            stepDescription: $description,
        );
    }
}
