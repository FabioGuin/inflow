<?php

namespace InFlow\Services\Mapping;

use InFlow\Enums\TransformType;

/**
 * Service for formatting transform information for display.
 *
 * Handles the presentation logic of formatting transform types and their labels
 * for display in the CLI interface.
 */
readonly class TransformFormatterService
{
    /**
     * Format available transforms for display.
     *
     * Returns an array mapping transform keys to their display labels.
     *
     * @param  array<TransformType>  $transformTypes  Array of available transform types
     * @param  string|null  $castType  The cast type suffix (e.g., 'int', 'float') if applicable
     * @return array<string, string> Array mapping transform keys to display labels
     */
    public function formatForDisplay(array $transformTypes, ?string $castType = null): array
    {
        $formatted = [];

        // Interactive transforms that require user input (key ends with :)
        $interactiveTransforms = [
            TransformType::Default,
            TransformType::RegexReplace,
            TransformType::ParseDate,
            TransformType::DateFormat,
            TransformType::Truncate,
            TransformType::Prefix,
            TransformType::Suffix,
            TransformType::Round,
            TransformType::Multiply,
            TransformType::Divide,
            TransformType::Coalesce,
            TransformType::Split,
        ];

        foreach ($transformTypes as $transformType) {
            if ($transformType === TransformType::Cast && $castType !== null) {
                $key = TransformType::Cast->value.':'.$castType;
                $label = $this->getCastLabel($castType);
                $formatted[$key] = $label;
            } elseif (in_array($transformType, $interactiveTransforms, true)) {
                $key = $transformType->value.':';
                $formatted[$key] = $this->getTransformLabel($transformType);
            } else {
                $key = $transformType->value;
                $formatted[$key] = $this->getTransformLabel($transformType);
            }
        }

        return $formatted;
    }

    /**
     * Get display label for a transform type.
     *
     * @param  TransformType  $transformType  The transform type
     * @return string The display label
     */
    private function getTransformLabel(TransformType $transformType): string
    {
        return match ($transformType) {
            // String transforms
            TransformType::Trim => 'Trim whitespace (e.g., "  hello  " → "hello")',
            TransformType::Upper => 'Uppercase (e.g., "hello" → "HELLO")',
            TransformType::Lower => 'Lowercase (e.g., "HELLO" → "hello")',
            TransformType::Capitalize => 'Capitalize first letter (e.g., "hello world" → "Hello world")',
            TransformType::Title => 'Title Case (e.g., "hello world" → "Hello World")',
            TransformType::Slugify => 'Slugify (e.g., "Hello World!" → "hello-world")',
            TransformType::SnakeCase => 'Snake case (e.g., "helloWorld" → "hello_world")',
            TransformType::CamelCase => 'Camel case (e.g., "hello_world" → "helloWorld")',
            TransformType::StripTags => 'Strip HTML (e.g., "<b>text</b>" → "text")',
            TransformType::CleanWhitespace => 'Clean whitespace (e.g., "a\tb\nc" → "a b c") - for single-line fields',
            TransformType::NormalizeMultiline => 'Normalize multiline (keeps paragraphs, cleans tabs) - for text/bio',
            TransformType::Truncate => 'Truncate to N chars (e.g., "hello world" → "hello...")',
            TransformType::Prefix => 'Add prefix (e.g., "123" → "SKU-123")',
            TransformType::Suffix => 'Add suffix (e.g., "file" → "file_v2")',
            TransformType::NullIfEmpty => 'Empty to null (e.g., "" → null)',

            // Numeric transforms
            TransformType::Round => 'Round to N decimals (e.g., 3.456 → 3.46)',
            TransformType::Floor => 'Floor (e.g., 12.7 → 12)',
            TransformType::Ceil => 'Ceil (e.g., 12.1 → 13)',
            TransformType::Multiply => 'Multiply by N (e.g., 10 × 100 → 1000)',
            TransformType::Divide => 'Divide by N (e.g., 1000 ÷ 100 → 10)',
            TransformType::ToCents => 'To cents (e.g., 12.99 → 1299)',
            TransformType::FromCents => 'From cents (e.g., 1299 → 12.99)',

            // Date transforms
            TransformType::ParseDate => '⚠️ Parse INPUT format (e.g., "15/05/2023" → DB date) - USE THIS FOR IMPORT',
            TransformType::DateFormat => 'Format OUTPUT (e.g., DB date → "15/05/2023") - for export only',
            TransformType::Timestamp => 'Unix timestamp (e.g., "2024-01-01" → 1704067200)',

            // Utility transforms
            TransformType::Default => 'Default value (e.g., null → "N/A")',
            TransformType::RegexReplace => 'Regex replace (e.g., "a1b2" → "aXbX")',
            TransformType::Coalesce => 'Fallback value (e.g., "" → "N/A")',
            TransformType::JsonDecode => 'Decode JSON (e.g., \'{"a":1}\' → array)',
            TransformType::Split => 'Split string (e.g., "a,b,c" → ["a","b","c"])',

            default => $transformType->value,
        };
    }

    /**
     * Get display label for a cast transform.
     *
     * @param  string  $castType  The cast type (e.g., 'int', 'float', 'date', 'bool')
     * @return string The display label
     */
    private function getCastLabel(string $castType): string
    {
        return match ($castType) {
            'int' => 'Cast to int (e.g., "42" → 42)',
            'float' => 'Cast to float (e.g., "3.14" → 3.14)',
            'date' => 'Cast to date (e.g., "2024-01-01" → Carbon)',
            'bool' => 'Cast to bool (e.g., "true" → true)',
            default => "Cast to {$castType}",
        };
    }
}
