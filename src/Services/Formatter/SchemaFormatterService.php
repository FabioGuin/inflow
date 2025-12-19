<?php

namespace InFlow\Services\Formatter;

use InFlow\Enums\TableHeader;
use InFlow\ValueObjects\ColumnMetadata;
use InFlow\ValueObjects\SourceSchema;

/**
 * Service for formatting schema data for display.
 *
 * Handles the business logic of formatting schema information for presentation.
 * Presentation logic (actual output) is handled by the caller.
 */
class SchemaFormatterService
{
    /**
     * Format schema data for table display.
     *
     * @param  SourceSchema  $schema  The source schema to format
     * @return array{headers: array<string>, table_data: array<int, array<int, string>>}
     */
    public function formatForTable(SourceSchema $schema): array
    {
        $tableData = [];
        foreach ($schema->columns as $column) {
            $tableData[] = $this->formatColumnRow($column, $schema->totalRows);
        }

        return [
            'headers' => TableHeader::schemaHeaders(),
            'table_data' => $tableData,
        ];
    }

    /**
     * Format a single column row for table display.
     *
     * @param  ColumnMetadata  $column  The column metadata
     * @param  int  $totalRows  Total number of rows in the schema
     * @return array<int, string> Formatted row data
     */
    private function formatColumnRow(ColumnMetadata $column, int $totalRows): array
    {
        $nullPct = $column->getNullPercentage($totalRows);
        $nullDisplay = number_format($column->nullCount).' ('.number_format($nullPct, 1).'%)';

        return [
            $column->name,
            "<fg=yellow>{$column->type->value}</>",
            $nullDisplay,
            number_format($column->uniqueCount),
            $this->formatValue($column->min),
            $this->formatValue($column->max),
        ];
    }

    /**
     * Format a value for display (handles null values).
     *
     * @param  mixed  $value  The value to format
     * @return string Formatted value
     */
    private function formatValue(mixed $value): string
    {
        return $value !== null ? (string) $value : '<fg=gray>-</>';
    }

    /**
     * Format examples for a column.
     *
     * @param  ColumnMetadata  $column  The column metadata
     * @return string|null Formatted examples string or null if no examples
     */
    public function formatExamples(ColumnMetadata $column): ?string
    {
        if (empty($column->examples)) {
            return null;
        }

        $examples = array_slice($column->examples, 0, 3);

        return implode(', ', $examples);
    }
}
