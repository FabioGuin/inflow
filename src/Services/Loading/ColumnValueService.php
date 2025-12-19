<?php

namespace InFlow\Services\Loading;

use InFlow\Transforms\TransformEngine;
use InFlow\ValueObjects\ColumnMapping;
use InFlow\ValueObjects\Row;

/**
 * Service for extracting and transforming column values.
 *
 * Handles business logic for:
 * - Extracting values from rows (including virtual columns)
 * - Applying default values
 * - Applying transformations
 *
 * Presentation logic (logging, errors) is handled by the caller.
 */
readonly class ColumnValueService
{
    public function __construct(
        private TransformEngine $transformEngine
    ) {}

    /**
     * Extract and transform value for a column mapping.
     *
     * Business logic: extracts value from row, applies defaults, applies transformations.
     *
     * @param  Row  $row  The source row
     * @param  ColumnMapping  $columnMapping  The column mapping
     * @return mixed The extracted and transformed value
     */
    public function extractValue(Row $row, ColumnMapping $columnMapping): mixed
    {
        // Handle virtual source columns (for default values, generated values, etc.)
        if ($this->isVirtualColumn($columnMapping->sourceColumn)) {
            return $columnMapping->default;
        }

        $value = $row->get($columnMapping->sourceColumn);

        // Apply default if value is empty
        if ($value === null || $value === '') {
            $value = $columnMapping->default;
        }

        // Apply transformations
        return $this->transformEngine->apply(
            $value,
            $columnMapping->transforms,
            ['row' => $row->toArray()]
        );
    }

    /**
     * Check if a source column is virtual (e.g., __default_*, __skip_*, __random_*).
     *
     * Business logic: determines if column is virtual.
     *
     * @param  string  $sourceColumn  The source column name
     * @return bool True if column is virtual
     */
    private function isVirtualColumn(string $sourceColumn): bool
    {
        return str_starts_with($sourceColumn, '__default_')
            || str_starts_with($sourceColumn, '__skip_')
            || str_starts_with($sourceColumn, '__random_');
    }
}
