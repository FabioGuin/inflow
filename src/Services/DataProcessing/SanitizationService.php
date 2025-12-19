<?php

namespace InFlow\Services\DataProcessing;

use InFlow\Contracts\SanitizationReportInterface;
use InFlow\Sanitizers\RawSanitizer;

/**
 * Service for sanitizing file content.
 *
 * Handles the business logic of content sanitization using RawSanitizer.
 * Presentation logic (displaying configuration, reports, etc.) is handled by the caller.
 */
readonly class SanitizationService
{
    public function __construct(
        private RawSanitizer $sanitizer
    ) {}

    /**
     * Sanitize content using the provided configuration.
     *
     * Uses the injected RawSanitizer instance with the given configuration to perform
     * sanitization, and returns both the sanitized content and the report.
     *
     * @param  string  $content  The raw content to sanitize
     * @param  array  $config  Sanitizer configuration array
     * @return array{0: string, 1: SanitizationReportInterface} Tuple of [sanitized content, report]
     */
    public function sanitize(string $content, array $config): array
    {
        $sanitized = $this->sanitizer->sanitize($content, $config);
        $report = $this->sanitizer->getReport();

        return [$sanitized, $report];
    }
}
