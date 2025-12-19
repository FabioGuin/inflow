<?php

namespace InFlow\Contracts;

interface SanitizerInterface
{
    /**
     * Sanitizes raw content and returns cleaned content
     */
    public function sanitize(string $content): string;

    /**
     * Returns a report of the sanitization operations performed
     */
    public function getReport(): SanitizationReportInterface;
}
