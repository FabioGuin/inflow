<?php

namespace InFlow\Services\Loading;

use Illuminate\Support\Facades\DB;

/**
 * Service for validating and truncating column values.
 *
 * Handles business logic for:
 * - Getting column max lengths from database
 * - Truncating values that exceed max length
 * - Tracking truncated fields
 *
 * Presentation logic (logging, warnings) is handled by the caller.
 */
class ColumnValidationService
{
    /**
     * Cache for column max lengths per model
     *
     * @var array<string, array<string, int|null>>
     */
    private static array $columnMaxLengthsCache = [];

    /**
     * Validate and truncate string value if it exceeds column max length.
     *
     * Business logic: checks max length, truncates if needed, returns truncated value and details.
     *
     * @param  string  $modelClass  The model class
     * @param  string  $attributeName  The attribute name
     * @param  string  $value  The value to validate
     * @return array{value: string, truncated: bool, details: array{field: string, original_length: int, max_length: int}|null} Validation result
     */
    public function validateAndTruncate(string $modelClass, string $attributeName, string $value): array
    {
        if ($value === '') {
            return ['value' => $value, 'truncated' => false, 'details' => null];
        }

        $maxLength = $this->getColumnMaxLength($modelClass, $attributeName);

        if ($maxLength === null || mb_strlen($value) <= $maxLength) {
            return ['value' => $value, 'truncated' => false, 'details' => null];
        }

        // Truncate value that exceeds max length
        $originalLength = mb_strlen($value);
        $truncatedValue = mb_substr($value, 0, $maxLength);

        return [
            'value' => $truncatedValue,
            'truncated' => true,
            'details' => [
                'field' => $attributeName,
                'original_length' => $originalLength,
                'max_length' => $maxLength,
            ],
        ];
    }

    /**
     * Get maximum length for a database column.
     *
     * Business logic: queries database schema to get column max length.
     *
     * @param  string  $modelClass  The model class
     * @param  string  $columnName  The column name
     * @return int|null Maximum length in characters, or null if unlimited (TEXT, LONGTEXT, etc.)
     */
    private function getColumnMaxLength(string $modelClass, string $columnName): ?int
    {
        $cacheKey = "{$modelClass}::{$columnName}";

        if (isset(self::$columnMaxLengthsCache[$cacheKey])) {
            return self::$columnMaxLengthsCache[$cacheKey];
        }

        try {
            $model = new $modelClass;
            $table = $model->getTable();

            $columns = DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$columnName]);

            if (empty($columns)) {
                self::$columnMaxLengthsCache[$cacheKey] = null;

                return null;
            }

            $column = $columns[0];
            $type = $column->Type;

            // Extract length from VARCHAR(255), CHAR(10), etc.
            if (preg_match('/^(varchar|char|varbinary|binary)\((\d+)\)/i', $type, $matches)) {
                $maxLength = (int) $matches[2];
                self::$columnMaxLengthsCache[$cacheKey] = $maxLength;

                return $maxLength;
            }

            // TEXT types have no explicit length limit in MySQL, but practical limits:
            // TINYTEXT: 255 bytes, TEXT: 65,535 bytes, MEDIUMTEXT: 16,777,215 bytes, LONGTEXT: 4GB
            // For UTF-8, we use character count (not byte count) for safety
            if (stripos($type, 'tinytext') !== false) {
                self::$columnMaxLengthsCache[$cacheKey] = 255;

                return 255;
            }
            if (stripos($type, 'text') !== false && stripos($type, 'tiny') === false) {
                // TEXT, MEDIUMTEXT, LONGTEXT - no practical limit for our purposes
                self::$columnMaxLengthsCache[$cacheKey] = null;

                return null;
            }

            // No length limit found
            self::$columnMaxLengthsCache[$cacheKey] = null;

            return null;
        } catch (\Exception $e) {
            // If we can't determine the length, don't truncate
            \inflow_report($e, 'debug', [
                'operation' => 'getColumnMaxLength',
                'model' => $modelClass,
                'column' => $columnName,
            ]);
            self::$columnMaxLengthsCache[$cacheKey] = null;

            return null;
        }
    }
}
