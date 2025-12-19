<?php

namespace InFlow\Services\Formatter;

/**
 * Service for formatting flow execution warnings.
 *
 * Handles presentation logic: formatting warning messages for empty rows
 * and truncated fields. Business logic is handled by other services.
 */
readonly class FlowWarningFormatterService
{
    /**
     * Format list of rows for display (e.g., "Row 1 (ID: 123), Row 5 (ID: 456)").
     *
     * Presentation: formats row list for warning messages.
     *
     * @param  array<int, array{row_number: int, id: string|null}>  $rows  The rows to format
     * @param  int  $maxItems  Maximum number of items to show
     * @return string Formatted row list
     */
    public function formatRowsList(array $rows, int $maxItems = 10): string
    {
        $items = [];
        $displayRows = array_slice($rows, 0, $maxItems);

        foreach ($displayRows as $row) {
            $rowNum = $row['row_number'];
            $id = $row['id'] ?? null;

            if ($id !== null) {
                $items[] = "Row {$rowNum} (ID: {$id})";
            } else {
                $items[] = "Row {$rowNum}";
            }
        }

        $result = implode(', ', $items);

        if (count($rows) > $maxItems) {
            $remaining = count($rows) - $maxItems;
            $result .= " and {$remaining} more";
        }

        return $result;
    }

    /**
     * Format list of truncated fields for display.
     *
     * Presentation: formats truncated fields list for warning messages.
     *
     * @param  array<int, array{row_number: int, id: string|null, field: string, original_length: int, max_length: int}>  $truncatedFields  The truncated fields to format
     * @param  int  $maxItems  Maximum number of items to show
     * @return string Formatted truncated fields list
     */
    public function formatTruncatedFieldsList(array $truncatedFields, int $maxItems = 10): string
    {
        $items = [];
        $displayFields = array_slice($truncatedFields, 0, $maxItems);

        foreach ($displayFields as $field) {
            $rowNum = $field['row_number'];
            $fieldName = $field['field'];
            $id = $field['id'] ?? null;
            $originalLength = $field['original_length'];
            $maxLength = $field['max_length'];

            $rowInfo = $id !== null ? "Row {$rowNum} (ID: {$id})" : "Row {$rowNum}";
            $items[] = "{$rowInfo}, field '{$fieldName}' ({$originalLength} → {$maxLength} chars)";
        }

        $result = implode('; ', $items);

        if (count($truncatedFields) > $maxItems) {
            $remaining = count($truncatedFields) - $maxItems;
            $result .= " and {$remaining} more";
        }

        return $result;
    }

    /**
     * Build warning message for empty rows.
     *
     * Presentation: builds warning message with formatted row list.
     *
     * @param  array<int, array{row_number: int, id: string|null}>  $emptyRows  The empty rows
     * @return array{message: string, metadata: array<string, mixed>} Warning message and metadata
     */
    public function buildEmptyRowsWarning(array $emptyRows): array
    {
        $emptyRowsCount = count($emptyRows);
        $emptyRowsList = $this->formatRowsList($emptyRows, 10);
        $message = "{$emptyRowsCount} empty row(s) were skipped during import";
        if (! empty($emptyRowsList)) {
            $message .= ": {$emptyRowsList}";
        }

        return [
            'message' => $message,
            'metadata' => [
                'empty_rows_count' => $emptyRowsCount,
                'empty_rows' => $emptyRows,
            ],
        ];
    }

    /**
     * Build warning message for truncated fields.
     *
     * Presentation: builds warning message with formatted truncated fields list.
     *
     * @param  array<int, array{row_number: int, id: string|null, field: string, original_length: int, max_length: int}>  $truncatedFieldsDetails  The truncated fields details
     * @return array{message: string, metadata: array<string, mixed>} Warning message and metadata
     */
    public function buildTruncatedFieldsWarning(array $truncatedFieldsDetails): array
    {
        $truncatedCount = count($truncatedFieldsDetails);
        $truncatedList = $this->formatTruncatedFieldsList($truncatedFieldsDetails, 10);
        $message = "{$truncatedCount} field(s) were truncated because they exceeded column maximum length";
        if (! empty($truncatedList)) {
            $message .= ": {$truncatedList}";
        }

        return [
            'message' => $message,
            'metadata' => [
                'truncated_fields_count' => $truncatedCount,
                'truncated_fields' => $truncatedFieldsDetails,
            ],
        ];
    }
}
