<?php

namespace InFlow\Services\Reporting;

use Carbon\Carbon;
use InFlow\ValueObjects\FlowRun;
use InFlow\ValueObjects\ProcessingContext;

/**
 * Generates detailed error reports for failed imports.
 */
class ErrorReportGenerator
{
    private string $reportPath;

    public function __construct(
        private readonly ErrorClassifier $errorClassifier,
        ?string $reportPath = null
    ) {
        $this->reportPath = $reportPath ?? storage_path('inflow/reports');
    }

    /**
     * Generate a detailed error report.
     *
     * @return string|null Path to the generated report, or null if no errors or skipped rows
     */
    public function generate(FlowRun $flowRun, ProcessingContext $context): ?string
    {
        // Generate report if there are errors OR skipped rows (validation failures)
        if ($flowRun->errorCount === 0 && $flowRun->skippedRows === 0) {
            return null;
        }

        $this->ensureDirectoryExists();

        $filename = $this->generateFilename($context);
        $filePath = $this->reportPath.'/'.$filename;

        $content = $this->buildReportContent($flowRun, $context);

        file_put_contents($filePath, $content);

        return $filePath;
    }

    private function ensureDirectoryExists(): void
    {
        if (! is_dir($this->reportPath)) {
            mkdir($this->reportPath, 0755, true);
        }
    }

    private function generateFilename(ProcessingContext $context): string
    {
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $sourceFile = pathinfo($context->filePath, PATHINFO_FILENAME);

        return "error-report_{$sourceFile}_{$timestamp}.txt";
    }

    private function calculateSuccessRate(FlowRun $flowRun): float
    {
        if ($flowRun->totalRows === 0) {
            return 0.0;
        }

        return ($flowRun->importedRows / $flowRun->totalRows) * 100;
    }

    private function buildReportContent(FlowRun $flowRun, ProcessingContext $context): string
    {
        $lines = array_merge(
            $this->buildHeader(),
            $this->buildSummary($flowRun, $context),
            $this->buildErrorDetails($flowRun),
            $this->buildMappingInfo($context),
            $this->buildFooter()
        );

        return implode("\n", $lines);
    }

    private function buildHeader(): array
    {
        return [
            str_repeat('=', 80),
            'INFLOW ERROR REPORT',
            str_repeat('=', 80),
            '',
        ];
    }

    private function buildSummary(FlowRun $flowRun, ProcessingContext $context): array
    {
        $modelClass = $context->mappingDefinition->mappings[0]->modelClass ?? 'Unknown';

        return [
            'SUMMARY',
            str_repeat('-', 40),
            'Generated: '.Carbon::now()->format('Y-m-d H:i:s'),
            'Source File: '.$context->filePath,
            'Target Model: '.$modelClass,
            '',
            'Total Rows: '.$flowRun->totalRows,
            'Imported: '.$flowRun->importedRows,
            'Skipped: '.$flowRun->skippedRows,
            'Errors: '.$flowRun->errorCount,
            'Success Rate: '.number_format($this->calculateSuccessRate($flowRun), 2).'%',
            'Duration: '.number_format($flowRun->duration ?? 0, 2).'s',
            '',
        ];
    }

    private function buildErrorDetails(FlowRun $flowRun): array
    {
        $lines = [
            str_repeat('=', 80),
            'ERROR DETAILS',
            str_repeat('=', 80),
            '',
        ];

        if (empty($flowRun->errors)) {
            $lines[] = 'No detailed error information available.';
            $lines[] = '';

            return $lines;
        }

        $errorsByType = [];
        foreach ($flowRun->errors as $index => $error) {
            $errorType = $this->getErrorType($error);
            $errorsByType[$errorType] = ($errorsByType[$errorType] ?? 0) + 1;

            $lines = array_merge($lines, $this->buildErrorEntry($index + 1, $error, $errorType));
        }

        if (count($errorsByType) > 0) {
            $lines = array_merge(
                array_slice($lines, 0, -count($flowRun->errors)),
                $this->buildErrorTypeSummary($errorsByType),
                array_slice($lines, -count($flowRun->errors))
            );
        }

        return $lines;
    }

    private function getErrorType(array $error): string
    {
        $validationErrors = $error['context']['validation_errors'] ?? $error['context']['errors'] ?? null;

        if ($validationErrors !== null && is_array($validationErrors)) {
            return $this->classifyValidationErrorType($validationErrors);
        }

        $classification = $this->errorClassifier->classifyMessage($error['message'] ?? '');

        return $classification['type'];
    }

    private function buildErrorEntry(int $errorNumber, array $error, string $errorType): array
    {
        $lines = [
            sprintf('[Error #%d] %s', $errorNumber, $errorType),
            str_repeat('-', 40),
        ];

        if (isset($error['row'])) {
            $lines[] = 'Row: '.$error['row'];
        }

        if (isset($error['message'])) {
            $lines[] = 'Message: '.$error['message'];
        }

        $validationErrors = $error['context']['validation_errors'] ?? $error['context']['errors'] ?? null;
        if ($validationErrors !== null && is_array($validationErrors)) {
            $lines = array_merge($lines, $this->buildValidationErrors($validationErrors, $error['context']['data'] ?? []));
        }

        if (isset($error['context']['sql'])) {
            $lines[] = '';
            $lines[] = 'SQL: '.$error['context']['sql'];
        }

        if (isset($error['context']['data']) && is_array($error['context']['data'])) {
            $lines = array_merge($lines, $this->buildRowData($error['context']['data']));
        }

        $lines[] = '';

        return $lines;
    }

    private function buildValidationErrors(array $validationErrors, array $rowData): array
    {
        $lines = ['', 'Validation Errors:'];

        foreach ($validationErrors as $field => $fieldErrors) {
            $errors = is_array($fieldErrors) ? $fieldErrors : [$fieldErrors];
            foreach ($errors as $fieldError) {
                $lines[] = "  • {$field}: {$fieldError}";
            }
        }

        $suggestions = $this->generateValidationSuggestions($validationErrors, $rowData);
        if (! empty($suggestions)) {
            $lines[] = '';
            $lines[] = 'Suggested Solutions:';
            foreach ($suggestions as $suggestion) {
                $lines[] = "  • {$suggestion}";
            }
        }

        return $lines;
    }

    private function buildRowData(array $data): array
    {
        $lines = ['', 'Row Data:'];

        foreach ($data as $key => $value) {
            $displayValue = is_null($value) ? '<null>' : (is_array($value) ? json_encode($value) : (string) $value);
            $lines[] = "  - {$key}: {$displayValue}";
        }

        return $lines;
    }

    private function buildErrorTypeSummary(array $errorsByType): array
    {
        $lines = [
            'ERROR TYPE SUMMARY',
            str_repeat('-', 40),
        ];

        foreach ($errorsByType as $type => $count) {
            $lines[] = "  • {$type}: {$count} occurrence(s)";
        }

        $lines[] = '';

        return $lines;
    }

    private function buildMappingInfo(ProcessingContext $context): array
    {
        $lines = [
            str_repeat('=', 80),
            'MAPPING CONFIGURATION',
            str_repeat('=', 80),
            '',
        ];

        if ($context->mappingDefinition === null) {
            return $lines;
        }

        foreach ($context->mappingDefinition->mappings as $mapping) {
            $lines[] = 'Model: '.$mapping->modelClass;
            $lines[] = 'Columns:';

            foreach ($mapping->columns as $col) {
                $transforms = empty($col->transforms) ? '' : ' ['.implode(', ', $col->transforms).']';
                $lines[] = "  - {$col->sourceColumn} → {$col->targetPath}{$transforms}";
            }

            if (! empty($mapping->options['unique_key'])) {
                $uniqueKey = is_array($mapping->options['unique_key'])
                    ? implode(', ', $mapping->options['unique_key'])
                    : $mapping->options['unique_key'];
                $lines[] = 'Unique Key: '.$uniqueKey;
                $lines[] = 'On Duplicate: '.($mapping->options['duplicate_strategy'] ?? 'error');
            }

            $lines[] = '';
        }

        return $lines;
    }

    private function buildFooter(): array
    {
        return [
            str_repeat('=', 80),
            'END OF REPORT',
            str_repeat('=', 80),
        ];
    }

    /**
     * Generate specific suggestions for validation errors.
     *
     * @param  array<string, array<string>|string>  $validationErrors  Validation errors by field
     * @param  array<string, mixed>  $rowData  The row data that failed validation
     * @return array<string> Array of suggestion strings
     */
    private function generateValidationSuggestions(array $validationErrors, array $rowData): array
    {
        $suggestions = [];

        foreach ($validationErrors as $field => $fieldErrors) {
            $errors = is_array($fieldErrors) ? $fieldErrors : [$fieldErrors];
            $currentValue = $rowData[$field] ?? null;

            foreach ($errors as $errorMessage) {
                $suggestion = $this->getSuggestionForValidationError($field, $errorMessage, $currentValue);
                if ($suggestion !== null) {
                    $suggestions[] = $suggestion;
                }
            }
        }

        return array_unique($suggestions);
    }

    /**
     * Get a specific suggestion for a validation error.
     *
     * @param  string  $field  The field name
     * @param  string  $errorMessage  The validation error message
     * @param  mixed  $currentValue  The current value that failed validation
     * @return string|null Suggestion string or null if no specific suggestion
     */
    private function getSuggestionForValidationError(string $field, string $errorMessage, mixed $currentValue): ?string
    {
        $currentLength = is_string($currentValue) ? strlen($currentValue) : 0;

        // Min length error
        if (preg_match('/must be at least (\d+) characters?/i', $errorMessage, $matches)) {
            $minLength = (int) $matches[1];

            if ($currentLength === 0) {
                return "Field '{$field}' is empty. Add a default value in the mapping or ensure source data contains a value.";
            }

            return "Field '{$field}' is too short ({$currentLength} chars, minimum {$minLength}). "
                ."Current value: '{$currentValue}'. "
                ."Add padding or ensure source data meets minimum length requirement.";
        }

        // Max length error
        if (preg_match('/must not be greater than (\d+) characters?/i', $errorMessage, $matches)) {
            $maxLength = (int) $matches[1];

            return "Field '{$field}' exceeds maximum length ({$currentLength} chars, maximum {$maxLength}). "
                ."Add 'truncate:{$maxLength}' transform to automatically truncate long values, "
                ."or manually shorten the value in source data.";
        }

        // Email validation error
        if (str_contains($errorMessage, 'must be a valid email address') || str_contains($errorMessage, 'email')) {
            $valueDisplay = $this->formatValueForDisplay($currentValue);

            return "Field '{$field}' contains an invalid email format. "
                ."Current value: '{$valueDisplay}'. "
                ."Check for typos, missing @ symbol, or invalid domain. "
                ."Example of valid format: 'user@example.com'";
        }

        // Size validation error (exact length)
        if (preg_match('/must be (\d+) characters?/i', $errorMessage, $matches)) {
            $requiredSize = (int) $matches[1];

            if ($currentLength < $requiredSize) {
                return "Field '{$field}' is too short ({$currentLength} chars, required {$requiredSize}). "
                    ."Add padding or ensure source data has exactly {$requiredSize} characters.";
            }

            return "Field '{$field}' is too long ({$currentLength} chars, required {$requiredSize}). "
                ."Add 'truncate:{$requiredSize}' transform or manually adjust source data.";
        }

        // Alpha validation errors
        if (str_contains($errorMessage, 'must only contain letters') || str_contains($errorMessage, 'alpha')) {
            // Check for alpha_dash: Laravel message says "letters, numbers, dashes, and underscores"
            if ((str_contains($errorMessage, 'dashes') && str_contains($errorMessage, 'underscores')) || str_contains($errorMessage, 'alpha_dash')) {
                return "Field '{$field}' contains invalid characters for alpha_dash format. "
                    ."Current value: '{$currentValue}'. "
                    ."Only letters, numbers, dashes (-), and underscores (_) are allowed. "
                    ."Remove spaces and other special characters. "
                    ."Use 'regex_replace' transform with pattern '/[^a-zA-Z0-9_-]/' to strip invalid characters, or fix source data.";
            }

            // Check for alpha_num: Laravel message says "letters and numbers" (without dashes/underscores)
            if ((str_contains($errorMessage, 'letters') && str_contains($errorMessage, 'numbers') && ! str_contains($errorMessage, 'dashes') && ! str_contains($errorMessage, 'underscores')) || str_contains($errorMessage, 'alpha_num')) {
                return "Field '{$field}' contains invalid characters for alpha_num format. "
                    ."Current value: '{$currentValue}'. "
                    ."Only letters and numbers are allowed. "
                    ."Remove spaces, dashes, underscores, and other special characters. "
                    ."Use 'regex_replace' transform with pattern '/[^a-zA-Z0-9]/' to strip invalid characters, or fix source data.";
            }

            // Default: alpha (only letters)
            return "Field '{$field}' contains non-letter characters. "
                ."Current value: '{$currentValue}'. "
                ."Only letters are allowed. "
                ."Remove numbers, spaces, dashes, underscores, and other special characters. "
                ."Use 'regex_replace' transform with pattern '/[^a-zA-Z]/' to strip invalid characters, or fix source data.";
        }

        // Numeric validation error
        if (str_contains($errorMessage, 'must be a number') || str_contains($errorMessage, 'numeric')) {
            return "Field '{$field}' must be numeric. "
                ."Current value: '{$currentValue}'. "
                ."Add 'cast:int' or 'cast:float' transform to convert the value, "
                ."or ensure source data contains valid numeric values.";
        }

        // Required field error
        if (str_contains($errorMessage, 'required') && ($currentValue === null || $currentValue === '')) {
            return "Field '{$field}' is required but is empty. "
                ."Add a default value in the mapping configuration, "
                ."or ensure source data contains a value for this field.";
        }

        // Unique constraint error
        if (str_contains($errorMessage, 'has already been taken') || str_contains($errorMessage, 'unique')) {
            return "Field '{$field}' value '{$currentValue}' already exists in the database. "
                ."Set 'duplicate_strategy' to 'update' in mapping options to update existing records, "
                ."or 'skip' to ignore duplicates. Alternatively, use a different unique value.";
        }

        // Date validation error
        if (str_contains($errorMessage, 'date') || str_contains($errorMessage, 'datetime')) {
            return "Field '{$field}' contains an invalid date format. "
                ."Current value: '{$currentValue}'. "
                ."Add 'cast:date' transform or ensure source data uses a valid date format (e.g., YYYY-MM-DD).";
        }

        // Boolean validation error
        if (str_contains($errorMessage, 'boolean')) {
            return "Field '{$field}' must be a boolean value. "
                ."Current value: '{$currentValue}'. "
                ."Add 'cast:bool' transform to convert strings like 'true'/'false' or 'yes'/'no' to boolean.";
        }

        // Integer validation error
        if (str_contains($errorMessage, 'must be an integer') || str_contains($errorMessage, 'integer')) {
            return "Field '{$field}' must be an integer. "
                ."Current value: '{$currentValue}'. "
                ."Add 'cast:int' transform to convert the value, or ensure source data contains whole numbers.";
        }

        // Min value error (numeric)
        if (preg_match('/must be at least (\d+(?:\.\d+)?)/i', $errorMessage, $matches)) {
            $minValue = $matches[1];

            return "Field '{$field}' value '{$currentValue}' is below minimum ({$minValue}). "
                ."Ensure source data contains values >= {$minValue}, or adjust validation rules.";
        }

        // Max value error (numeric)
        if (preg_match('/must not be greater than (\d+(?:\.\d+)?)/i', $errorMessage, $matches)) {
            $maxValue = $matches[1];

            return "Field '{$field}' value '{$currentValue}' exceeds maximum ({$maxValue}). "
                ."Ensure source data contains values <= {$maxValue}, or adjust validation rules.";
        }

        // Regex validation error
        if (str_contains($errorMessage, 'format is invalid') || str_contains($errorMessage, 'regex')) {
            return "Field '{$field}' does not match the required format. "
                ."Current value: '{$currentValue}'. "
                ."Check the validation rule pattern and ensure source data matches the expected format.";
        }

        return null;
    }

    /**
     * Format value for display, truncating if too long.
     */
    private function formatValueForDisplay(mixed $value, int $maxLength = 50): string
    {
        $stringValue = (string) $value;

        return strlen($stringValue) > $maxLength ? substr($stringValue, 0, $maxLength).'...' : $stringValue;
    }

    /**
     * Classify validation error type based on validation errors.
     *
     * @param  array<string, array<string>|string>  $validationErrors  Validation errors by field
     * @return string Error type classification
     */
    private function classifyValidationErrorType(array $validationErrors): string
    {
        $types = [];

        foreach ($validationErrors as $field => $fieldErrors) {
            $errors = is_array($fieldErrors) ? $fieldErrors : [$fieldErrors];

            foreach ($errors as $errorMessage) {
                if (preg_match('/must be at least (\d+) characters?/i', $errorMessage)) {
                    $types[] = "Field length too short ({$field})";
                } elseif (preg_match('/must not be greater than (\d+) characters?/i', $errorMessage)) {
                    $types[] = "Field length too long ({$field})";
                } elseif (str_contains($errorMessage, 'email')) {
                    $types[] = "Invalid email format ({$field})";
                } elseif (str_contains($errorMessage, 'must only contain letters') || str_contains($errorMessage, 'alpha')) {
                    $types[] = "Non-alphabetic characters ({$field})";
                } elseif (preg_match('/must be (\d+) characters?/i', $errorMessage)) {
                    $types[] = "Incorrect field size ({$field})";
                } elseif (str_contains($errorMessage, 'numeric') || str_contains($errorMessage, 'number')) {
                    $types[] = "Invalid numeric value ({$field})";
                } elseif (str_contains($errorMessage, 'required')) {
                    $types[] = "Missing required field ({$field})";
                } elseif (str_contains($errorMessage, 'unique') || str_contains($errorMessage, 'already been taken')) {
                    $types[] = "Duplicate value ({$field})";
                } elseif (str_contains($errorMessage, 'date') || str_contains($errorMessage, 'datetime')) {
                    $types[] = "Invalid date format ({$field})";
                } else {
                    $types[] = "Validation failed ({$field})";
                }
            }
        }

        // Return the most specific error type, or combine if multiple
        if (count($types) === 1) {
            return $types[0];
        }

        return 'Multiple validation errors ('.implode(', ', array_unique($types)).')';
    }
}
