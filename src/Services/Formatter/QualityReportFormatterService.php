<?php

namespace InFlow\Services\Formatter;

use InFlow\ValueObjects\QualityReport;

/**
 * Service for formatting quality report data for display.
 *
 * Handles the business logic of formatting quality report information for presentation.
 * Presentation logic (actual output) is handled by the caller.
 */
class QualityReportFormatterService
{
    /**
     * Format quality report for display.
     *
     * @param  QualityReport  $report  The quality report to format
     * @return array{errors: array<int, array{message: string}>, warnings: array<int, array{message: string}>, anomalies: array<int, array{column: string, type: string, count: int, details: array<int, array{value: string, count: int|null}>}>}
     */
    public function formatForDisplay(QualityReport $report): array
    {
        return [
            'errors' => $this->formatErrors($report->errors),
            'warnings' => $this->formatWarnings($report->warnings),
            'anomalies' => $this->formatAnomalies($report->anomalies),
        ];
    }

    /**
     * Format errors for display.
     *
     * @param  array<string>  $errors  The errors to format
     * @return array<int, array{message: string}>
     */
    private function formatErrors(array $errors): array
    {
        $formatted = [];
        foreach ($errors as $error) {
            $formatted[] = [
                'message' => $error,
            ];
        }

        return $formatted;
    }

    /**
     * Format warnings for display.
     *
     * @param  array<string>  $warnings  The warnings to format
     * @return array<int, array{message: string}>
     */
    private function formatWarnings(array $warnings): array
    {
        $formatted = [];
        foreach ($warnings as $warning) {
            $formatted[] = [
                'message' => $warning,
            ];
        }

        return $formatted;
    }

    /**
     * Format anomalies for display.
     *
     * @param  array<string, array>  $anomalies  The anomalies to format
     * @return array<int, array{column: string, type: string, count: int, details: array<int, array{value: string, count: int|null}>}>
     */
    private function formatAnomalies(array $anomalies): array
    {
        $formatted = [];
        foreach ($anomalies as $columnName => $anomalyData) {
            if (isset($anomalyData['duplicates'])) {
                $duplicates = $anomalyData['duplicates'];
                $formatted[] = [
                    'column' => $columnName,
                    'type' => 'duplicates',
                    'count' => count($duplicates),
                    'details' => $this->formatDuplicateDetails($duplicates),
                ];
            }
            if (isset($anomalyData['invalid_dates'])) {
                $invalidDates = $anomalyData['invalid_dates'];
                $formatted[] = [
                    'column' => $columnName,
                    'type' => 'invalid_dates',
                    'count' => count($invalidDates),
                    'details' => $this->formatInvalidDateDetails($invalidDates),
                ];
            }
        }

        return $formatted;
    }

    /**
     * Format duplicate details for display.
     *
     * @param  array<string, int>  $duplicates  The duplicates data (value => count)
     * @return array<int, array{value: string, count: int}>
     */
    private function formatDuplicateDetails(array $duplicates): array
    {
        $formatted = [];
        foreach ($duplicates as $value => $count) {
            $formatted[] = [
                'value' => (string) $value,
                'count' => $count,
            ];
        }

        return $formatted;
    }

    /**
     * Format invalid date details for display.
     *
     * @param  array<string>  $invalidDates  The invalid dates
     * @return array<int, array{value: string, count: null}>
     */
    private function formatInvalidDateDetails(array $invalidDates): array
    {
        $formatted = [];
        foreach ($invalidDates as $date) {
            $formatted[] = [
                'value' => (string) $date,
                'count' => null,
            ];
        }

        return $formatted;
    }

    /**
     * Get limited examples for verbose display.
     *
     * @param  array<int, array{value: string, count: int|null}>  $details  The details to limit
     * @return array<int, array{value: string, count: int|null}>
     */
    public function getLimitedExamples(array $details): array
    {
        return array_slice($details, 0, 3);
    }
}
