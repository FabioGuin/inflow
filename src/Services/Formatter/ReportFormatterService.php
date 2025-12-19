<?php

namespace InFlow\Services\Formatter;

use InFlow\Contracts\SanitizationReportInterface;
use InFlow\Sanitizers\SanitizerStatLabels;

/**
 * Service for formatting reports for display.
 *
 * Handles the business logic of formatting report data for presentation.
 * Presentation logic (actual output) is handled by the caller.
 */
class ReportFormatterService
{
    /**
     * Format sanitization report data for display.
     *
     * Prepares statistics, decisions, and affected rows in a format ready for display.
     *
     * @param  SanitizationReportInterface  $report  The sanitization report
     * @return array{statistics: array<int, array{label: string, value: string}>, decisions: array<int, string>, affected_rows: array<int, string>, has_content: bool}
     */
    public function formatSanitizationReport(SanitizationReportInterface $report): array
    {
        $statistics = $this->formatStatistics($report->getStatistics());
        $decisions = $report->getDecisions();
        $affectedRows = $this->formatAffectedRows($report->getAffectedRows());

        return [
            'statistics' => $statistics,
            'decisions' => $decisions,
            'affected_rows' => $affectedRows,
            'has_content' => ! empty($statistics) || ! empty($decisions) || ! empty($affectedRows),
        ];
    }

    /**
     * Format statistics for table display.
     *
     * @param  array<string, mixed>  $statistics  Raw statistics array
     * @return array<int, array{label: string, value: string}>
     */
    private function formatStatistics(array $statistics): array
    {
        $formatted = [];

        foreach ($statistics as $key => $value) {
            $formatted[] = [
                'label' => $this->formatStatKey($key),
                'value' => (string) $value,
            ];
        }

        return $formatted;
    }

    /**
     * Format affected rows for display.
     *
     * Limits the number of examples shown and formats them as strings.
     *
     * @param  array<int, mixed>  $affectedRows  Raw affected rows array
     * @return array<int, string> Formatted affected rows
     */
    private function formatAffectedRows(array $affectedRows): array
    {
        $maxExamples = 3;
        $displayRows = array_slice($affectedRows, 0, $maxExamples);

        return array_map(function ($row) {
            return is_array($row) ? json_encode($row, JSON_UNESCAPED_UNICODE) : (string) $row;
        }, $displayRows);
    }

    /**
     * Get remaining count for affected rows.
     *
     * @param  array<int, mixed>  $affectedRows  Raw affected rows array
     * @return int Number of rows not shown
     */
    public function getRemainingAffectedRowsCount(array $affectedRows): int
    {
        $maxExamples = 3;

        return max(0, count($affectedRows) - $maxExamples);
    }

    /**
     * Format statistics key for display.
     *
     * Uses SanitizerStatLabels enum to get human-readable labels.
     *
     * @param  string  $key  The statistics key
     * @return string The formatted label
     */
    private function formatStatKey(string $key): string
    {
        return SanitizerStatLabels::for($key);
    }
}
