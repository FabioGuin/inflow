<?php

namespace InFlow\ViewModels;

/**
 * View Model for quality report display
 */
readonly class QualityReportViewModel
{
    /**
     * @param  array<string>  $errors
     * @param  array<string>  $warnings
     */
    public function __construct(
        public string $title,
        public array $errors,
        public array $warnings,
        public bool $hasIssues,
    ) {}
}
