<?php

namespace InFlow\Services\Reporting;

use Illuminate\Database\QueryException;
use InFlow\Exceptions\RelationResolutionException;

/**
 * Classifies ETL errors and provides diagnostic hints.
 *
 * Centralized error classification for consistent messaging across:
 * - CLI output (ExecuteFlowPipe)
 * - Error reports (ErrorReportGenerator)
 * - Logs
 */
class ErrorClassifier
{
    /**
     * Classify an error and provide diagnostic information.
     *
     * @return array{type: string, hint: string|null}
     */
    public function classify(\Throwable $e): array
    {
        // Handle relation resolution errors with detailed context
        if ($e instanceof RelationResolutionException) {
            return $this->classifyRelationResolutionError($e);
        }

        if ($e instanceof QueryException) {
            return $this->classifyQueryException($e);
        }

        // Fallback: try to classify by message
        return $this->classifyByMessage($e->getMessage());
    }

    /**
     * Classify from error message string only (for reports).
     *
     * @return array{type: string, hint: string|null}
     */
    public function classifyMessage(string $message): array
    {
        return $this->classifyByMessage($message);
    }

    private function classifyRelationResolutionError(RelationResolutionException $e): array
    {
        $hint = match ($e->errorType) {
            'missing_required' => $this->buildHint(
                "The related model '{$e->relationName}' requires additional fields that are not mapped.",
                [
                    'Make fields nullable in the related model\'s migration',
                    'Add the missing columns to your source file',
                    'Disable \'create_if_missing\' and ensure related records exist',
                ]
            ),
            'unique_violation' => $this->buildHint(
                "A {$e->relationName} with conflicting values already exists.",
                [
                    'Check for duplicates in source data',
                    'Use update strategy instead of create',
                ]
            ),
            'data_too_long' => $this->buildHint(
                "Value for {$e->lookupField}='{$e->lookupValue}' exceeds the maximum column length.",
                [
                    'Truncate the value in source data',
                    'Add truncate transform (e.g., truncate:255)',
                    'Increase column size in migration',
                ]
            ),
            default => "Failed to resolve relation '{$e->relationName}': {$e->getMessage()}",
        };

        return [
            'type' => "Cannot create/lookup related '{$e->relationName}' ({$e->errorType})",
            'hint' => $hint,
        ];
    }

    private function classifyQueryException(QueryException $e): array
    {
        $message = $e->getMessage();

        return $this->classifyByMessage($message);
    }

    private function classifyByMessage(string $message): array
    {
        // FK / relation not resolved: Field 'X' doesn't have a default value
        if (preg_match("/Field '([^']+)' doesn't have a default value/i", $message, $match)) {
            $fieldName = $match[1];

            if (str_ends_with($fieldName, '_id')) {
                $relationName = substr($fieldName, 0, -3);

                return [
                    'type' => "Relation '{$relationName}' not resolved (missing {$fieldName})",
                    'hint' => $this->buildHint(
                        "The '{$relationName}' relation was not resolved before saving.",
                        [
                            'Lookup field was empty/null in source data',
                            '\'create_if_missing\' is enabled but required fields for '.$relationName.' are not mapped',
                            'Lookup found no matching record and creation failed silently',
                        ],
                        [
                            'Disable \'create_if_missing\' if you only want to link to existing records',
                            'Ensure source data has values for the lookup field',
                            'Map all required fields for the related model',
                        ]
                    ),
                ];
            }

            return [
                'type' => "Missing required field '{$fieldName}'",
                'hint' => "The field '{$fieldName}' is NOT NULL but no value was provided. Check your mapping or add a default value.",
            ];
        }

        // NULL violation: Column 'X' cannot be null
        if (preg_match("/Column '([^']+)' cannot be null/i", $message, $match)) {
            $fieldName = $match[1];

            if (str_ends_with($fieldName, '_id')) {
                $relationName = substr($fieldName, 0, -3);

                return [
                    'type' => "Relation '{$relationName}' returned NULL",
                    'hint' => $this->buildHint(
                        "The relation lookup for '{$relationName}' failed to find or create a record.",
                        [
                            'Is the lookup field empty in source?',
                            'If \'create_if_missing\', are all required fields mapped?',
                        ]
                    ),
                ];
            }

            return [
                'type' => "NULL value for required field '{$fieldName}'",
                'hint' => "Field '{$fieldName}' cannot be NULL. Check if the source column is empty or the mapping/transform is incorrect.",
            ];
        }

        // Type mismatch: Incorrect integer value
        if (preg_match("/Incorrect integer value: '([^']+)' for column '([^']+)'/i", $message, $match)) {
            $value = $match[1];
            $column = $match[2];

            // Check if it's a boolean-like value
            $isBooleanValue = in_array(strtolower($value), ['true', 'false', 'yes', 'no', 'on', 'off'], true);

            // Check if it's a FK column
            $isFkColumn = str_ends_with($column, '_id');

            if ($isBooleanValue) {
                return [
                    'type' => "Boolean not cast (column '{$column}' received '{$value}')",
                    'hint' => $this->buildHint(
                        "Column '{$column}' expects an integer (0/1) but received a string boolean ('{$value}').",
                        null,
                        [
                            'Add the cast:bool transform to convert boolean strings to integers',
                            'Example: "true" → 1, "false" → 0',
                        ]
                    ),
                ];
            }

            if ($isFkColumn) {
                $relationName = substr($column, 0, -3);

                return [
                    'type' => "FK column received non-ID value ('{$column}')",
                    'hint' => $this->buildHint(
                        "Column '{$column}' expects an integer ID but received '{$value}'.",
                        null,
                        [
                            "Map to a relation path (e.g., {$relationName}.name) instead of the ID field",
                            'Enable \'create_if_missing\' to auto-create related records',
                        ]
                    ),
                ];
            }

            return [
                'type' => "Type mismatch (integer column '{$column}' received '{$value}')",
                'hint' => $this->buildHint(
                    "Column '{$column}' expects an integer but received a non-numeric value.",
                    null,
                    [
                        'Apply a cast transform (e.g., cast:int) to convert the value',
                        'Check if the source column contains valid numeric data',
                    ]
                ),
            ];
        }

        // Datetime error
        if (str_contains($message, 'Incorrect datetime value') || str_contains($message, 'Invalid datetime format')) {
            return [
                'type' => 'Invalid datetime value',
                'hint' => $this->buildHint(
                    'A date/datetime column received an unparseable value.',
                    null,
                    [
                        'Add a date parser transform (e.g., cast:date)',
                        'Check source data format matches expected format',
                    ]
                ),
            ];
        }

        // Unique constraint
        if (str_contains($message, 'Duplicate entry') || str_contains($message, 'UNIQUE constraint')) {
            return [
                'type' => 'Duplicate key violation',
                'hint' => $this->buildHint(
                    'A record with the same unique key already exists.',
                    null,
                    [
                        'Set duplicate_strategy to \'update\' or \'skip\' in mapping options',
                        'Check for duplicates in source data',
                    ]
                ),
            ];
        }

        // Foreign key constraint
        if (str_contains($message, 'foreign key constraint') || str_contains($message, 'Integrity constraint violation')) {
            return [
                'type' => 'Foreign key constraint violation',
                'hint' => $this->buildHint(
                    'A referenced record does not exist in the related table.',
                    null,
                    [
                        'Enable \'create_if_missing\' to auto-create related records',
                        'Ensure related records exist before importing',
                    ]
                ),
            ];
        }

        // Data too long
        if (str_contains($message, 'Data too long') || str_contains($message, 'Data truncated')) {
            return [
                'type' => 'Data too long for column',
                'hint' => $this->buildHint(
                    'A value exceeds the maximum column length.',
                    null,
                    [
                        'Add a truncate transform (e.g., truncate:255)',
                        'Increase column size in migration',
                    ]
                ),
            ];
        }

        // Validation errors (from ValidationException)
        if (str_contains($message, 'Validation failed')) {
            return [
                'type' => 'Validation error',
                'hint' => 'One or more fields failed validation. See validation errors section for details.',
            ];
        }

        return [
            'type' => 'Unhandled error',
            'hint' => null,
        ];
    }

    /**
     * Build a formatted hint string.
     *
     * @param  string  $description  Main description
     * @param  array<string>|null  $causes  Possible causes
     * @param  array<string>|null  $solutions  Suggested solutions
     */
    private function buildHint(string $description, ?array $causes = null, ?array $solutions = null): string
    {
        $lines = [$description];

        if ($causes !== null && count($causes) > 0) {
            $lines[] = 'Possible causes:';
            foreach ($causes as $i => $cause) {
                $lines[] = '  '.($i + 1).'. '.$cause;
            }
        }

        if ($solutions !== null && count($solutions) > 0) {
            $lines[] = 'Solutions:';
            foreach ($solutions as $solution) {
                $lines[] = '  • '.$solution;
            }
        }

        return implode("\n", $lines);
    }
}
