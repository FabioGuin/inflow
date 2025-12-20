<?php

namespace InFlow\ValueObjects\Data;

/**
 * Value Object representing quality report with warnings and errors
 */
readonly class QualityReport
{
    /**
     * @param  array<string>  $warnings
     * @param  array<string>  $errors
     * @param  array<string, array>  $anomalies
     */
    public function __construct(
        public array $warnings = [],
        public array $errors = [],
        public array $anomalies = []
    ) {}

    /**
     * Check if report has any issues
     */
    public function hasIssues(): bool
    {
        return ! empty($this->warnings) || ! empty($this->errors) || ! empty($this->anomalies);
    }

    /**
     * Check if report has critical errors
     */
    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    /**
     * Get total number of issues
     */
    public function getTotalIssues(): int
    {
        return count($this->warnings) + count($this->errors) + count($this->anomalies);
    }

    /**
     * Returns the report as an array
     */
    public function toArray(): array
    {
        return [
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'anomalies' => $this->anomalies,
            'has_issues' => $this->hasIssues(),
            'has_errors' => $this->hasErrors(),
            'total_issues' => $this->getTotalIssues(),
        ];
    }
}

