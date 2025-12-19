<?php

namespace InFlow\Services\Formatter;

/**
 * Service for formatting preview data for display.
 *
 * Handles the business logic of formatting preview data for presentation.
 * Presentation logic (actual output) is handled by the caller.
 */
class DataPreviewFormatterService
{
    /**
     * Format preview data for table display.
     *
     * @param  array<int, array<string, mixed>>  $rows  The rows to format
     * @param  array<string>|null  $headers  Optional headers from reader
     * @return array{headers: array<string>|null, table_data: array<int, array<int, mixed>>, has_headers: bool}
     */
    public function formatForTable(array $rows, ?array $headers): array
    {
        if ($headers !== null) {
            // Display as table with headers
            $tableData = [];
            foreach ($rows as $row) {
                $rowData = [];
                foreach ($headers as $header) {
                    $value = $row[$header] ?? null;
                    // Convert arrays and objects to JSON strings for display
                    if (is_array($value) || is_object($value)) {
                        $rowData[] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } else {
                        $rowData[] = $value;
                    }
                }
                $tableData[] = $rowData;
            }

            return [
                'headers' => $headers,
                'table_data' => $tableData,
                'has_headers' => true,
            ];
        }

        // Display as simple list (no headers)
        return [
            'headers' => null,
            'table_data' => [],
            'has_headers' => false,
        ];
    }

    /**
     * Format preview data for list display (when no headers available).
     *
     * @param  array<int, array<string, mixed>>  $rows  The rows to format
     * @return array<int, array{index: int, row_number: int, data: string}>
     */
    public function formatForList(array $rows): array
    {
        $formatted = [];
        foreach ($rows as $index => $row) {
            $formatted[] = [
                'index' => $index,
                'row_number' => $index + 1,
                'data' => json_encode($row),
            ];
        }

        return $formatted;
    }
}
