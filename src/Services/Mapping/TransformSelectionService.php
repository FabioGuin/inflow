<?php

namespace InFlow\Services\Mapping;

use InFlow\Enums\Data\TransformType;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;
use InFlow\Transforms\Date\DateFormatTransform;
use InFlow\Transforms\Date\ParseDateTransform;
use InFlow\Transforms\Numeric\DivideTransform;
use InFlow\Transforms\Numeric\MultiplyTransform;
use InFlow\Transforms\Numeric\RoundTransform;
use InFlow\Transforms\String\PrefixTransform;
use InFlow\Transforms\String\SuffixTransform;
use InFlow\Transforms\String\TruncateTransform;
use InFlow\Transforms\Utility\CoalesceTransform;
use InFlow\Transforms\Utility\DefaultTransform;
use InFlow\Transforms\Utility\RegexReplaceTransform;
use InFlow\Transforms\Utility\SplitTransform;
use InFlow\ValueObjects\Data\ColumnMetadata;

/**
 * Service for determining available transforms based on column metadata.
 *
 * Handles the business logic of determining which transforms are available
 * for a given column type. Presentation logic (labels, formatting) is handled
 * by the caller or a separate formatter service.
 */
readonly class TransformSelectionService
{
    /**
     * Get available transform types based on target field type.
     *
     * The target field type determines which transforms are relevant.
     * For example, when mapping a string column to a date field,
     * date transforms (parse_date) should be shown.
     *
     * @param  ColumnMetadata|null  $columnMeta  The source column metadata
     * @param  string|null  $targetType  The target field type ('date', 'int', 'float', 'bool', 'string', etc.)
     * @param  string|null  $dbType  The actual database column type (e.g., 'int', 'decimal', 'float')
     * @return array<TransformType> Array of available transform types
     */
    public function getAvailableTransformTypes(?ColumnMetadata $columnMeta, ?string $targetType = null, ?string $dbType = null): array
    {
        $transforms = [];

        // Base transforms for all types
        $transforms[] = TransformType::Trim;
        $transforms[] = TransformType::Default;
        $transforms[] = TransformType::Coalesce;
        $transforms[] = TransformType::NullIfEmpty;

        // Determine effective type: prefer target type, fallback to source type
        $effectiveType = $this->determineEffectiveType($targetType, $columnMeta);

        // Add type-specific transforms based on target/effective type
        if ($this->isStringEffectiveType($effectiveType)) {
            $transforms = array_merge($transforms, $this->getStringTransforms());
        }

        if ($this->isNumericEffectiveType($effectiveType)) {
            $transforms = array_merge($transforms, $this->getNumericTransforms($effectiveType, $dbType));
        }

        if ($this->isDateEffectiveType($effectiveType)) {
            $transforms = array_merge($transforms, $this->getDateTransforms());
        }

        if ($this->isBoolEffectiveType($effectiveType)) {
            $transforms[] = TransformType::Cast;
        }

        // Remove duplicates
        $uniqueValues = array_unique(array_map(fn (TransformType $t) => $t->value, $transforms));

        return array_map(fn (string $value) => TransformType::from($value), $uniqueValues);
    }

    /**
     * Determine the effective type for transform selection.
     *
     * Priority: target field type > source column type > string (fallback)
     */
    private function determineEffectiveType(?string $targetType, ?ColumnMetadata $columnMeta): string
    {
        if ($targetType !== null) {
            return strtolower($targetType);
        }

        if ($columnMeta !== null) {
            return $columnMeta->type->value;
        }

        return 'string';
    }

    private function isStringEffectiveType(string $type): bool
    {
        return in_array($type, ['string', 'email', 'text', 'varchar'], true);
    }

    private function isNumericEffectiveType(string $type): bool
    {
        return in_array($type, ['int', 'integer', 'float', 'decimal', 'double', 'numeric'], true);
    }

    private function isDateEffectiveType(string $type): bool
    {
        return in_array($type, ['date', 'datetime', 'timestamp', 'time'], true);
    }

    private function isBoolEffectiveType(string $type): bool
    {
        return in_array($type, ['bool', 'boolean'], true);
    }

    /** @return array<TransformType> */
    private function getStringTransforms(): array
    {
        return [
            TransformType::Upper,
            TransformType::Lower,
            TransformType::Capitalize,
            TransformType::Title,
            TransformType::Slugify,
            TransformType::SnakeCase,
            TransformType::CamelCase,
            TransformType::StripTags,
            TransformType::CleanWhitespace,
            TransformType::NormalizeMultiline,
            TransformType::Truncate,
            TransformType::Prefix,
            TransformType::Suffix,
            TransformType::RegexReplace,
            TransformType::Split,
            TransformType::JsonDecode,
            TransformType::Cast,
        ];
    }

    /**
     * Get numeric transforms filtered by specific cast type.
     *
     * When database type is available and differs from cast type, use DB type to filter transforms.
     * For example, if cast is 'decimal' but DB type is 'int', don't show floor/ceil.
     *
     * For integer types (from DB or cast), only show transforms that make sense for integers.
     * For float types (from DB), show round, floor, and ceil operations.
     * For decimal casts (with precision), show only round (floor/ceil don't make sense with decimal precision).
     *
     * @param  string  $castType  The specific cast type ('int', 'float', 'decimal', etc.)
     * @param  string|null  $dbType  The actual database column type (e.g., 'int', 'decimal', 'float')
     * @return array<TransformType>
     */
    private function getNumericTransforms(string $castType, ?string $dbType = null): array
    {
        $transforms = [
            TransformType::Multiply,
            TransformType::Divide,
            TransformType::Cast,
        ];

        // Always check database type when available
        // If DB type is 'int', don't show floor/ceil/round even if cast is 'decimal'
        // because the database cannot store decimal values
        if ($dbType === 'int') {
            // Integer types from database: no floor/ceil/round
            $transforms[] = TransformType::ToCents;
            $transforms[] = TransformType::FromCents;

            return $transforms;
        }

        // Round is useful for both float and decimal types
        if (in_array($castType, ['float', 'decimal', 'double', 'numeric'], true)) {
            $transforms[] = TransformType::Round;
        }

        // Floor and Ceil are only relevant for float types (without precision)
        // For decimal types, these don't make sense as the cast already handles decimal precision
        // Also, never show floor/ceil if DB type is 'int' (already handled above)
        if (in_array($castType, ['float', 'double'], true)) {
            $transforms[] = TransformType::Floor;
            $transforms[] = TransformType::Ceil;
        }

        // ToCents/FromCents are useful for both int and float (for price handling)
        $transforms[] = TransformType::ToCents;
        $transforms[] = TransformType::FromCents;

        return $transforms;
    }

    /** @return array<TransformType> */
    private function getDateTransforms(): array
    {
        return [
            TransformType::ParseDate,
            TransformType::DateFormat,
            TransformType::Timestamp,
            TransformType::Cast,
        ];
    }

    /**
     * Get the cast type suffix for a target type.
     *
     * Returns the type suffix to use with TransformType::Cast (e.g., 'int', 'float', 'date', 'bool').
     * Uses ModelCastService::parseCastType to avoid code duplication (DRY principle).
     *
     * @param  string|null  $targetType  The target field type
     * @return string|null The cast type suffix, or null if no cast is applicable
     */
    public function getCastTypeForTarget(?string $targetType): ?string
    {
        if ($targetType === null) {
            return null;
        }

        // Use ModelCastService to parse cast type (DRY principle)
        $parsed = ModelCastService::parseCastType($targetType);

        return $parsed['type'];
    }

    /**
     * Build options list with suggested transforms first.
     *
     * Suggested transforms appear at the top for visibility.
     *
     * @param  array<string, string>  $availableTransforms  Available transforms (key => label)
     * @param  array<string>  $suggestedTransforms  Suggested transform keys
     * @return array<string, string> Options with suggested items first
     */
    public function buildOptionsWithSuggestions(array $availableTransforms, array $suggestedTransforms): array
    {
        $suggested = [];
        $others = [];

        foreach ($availableTransforms as $key => $label) {
            if (in_array($key, $suggestedTransforms, true)) {
                $suggested[$key] = $label.' (suggested)';
            } else {
                $others[$key] = $label;
            }
        }

        // Suggested items first, then others
        return array_merge($suggested, $others);
    }

    /**
     * Get default transforms for selection.
     *
     * Pre-selects all suggested transforms to provide sensible defaults.
     * User can still deselect if needed.
     *
     * @param  array<string, string>  $options  Available options
     * @param  array<string>  $suggestedTransforms  Suggested transform keys
     * @return array<string> Default transform keys
     */
    public function getDefaultTransforms(array $options, array $suggestedTransforms = []): array
    {
        $optionKeys = array_keys($options);

        // Pre-select all suggested transforms that are available in options
        $defaults = array_filter($suggestedTransforms, fn ($key) => in_array($key, $optionKeys, true));

        return array_values($defaults);
    }

    /**
     * Process selected transforms, handling interactive transforms.
     *
     * @param  array<string>  $selectedTransforms  Selected transform keys
     * @param  callable  $askForInput  Generic callback: (label, hint, examples) => string|null
     * @return array<string> Processed transform specifications
     */
    public function processSelectedTransforms(
        array $selectedTransforms,
        callable $askForInput
    ): array {
        if (empty($selectedTransforms)) {
            return [];
        }

        $transforms = [];

        foreach ($selectedTransforms as $transformKey) {
            // Check if this is an interactive transform (ends with :)
            if (str_ends_with($transformKey, ':')) {
                $baseKey = rtrim($transformKey, ':');
                $transformClass = $this->getTransformClass($baseKey);

                if ($transformClass !== null && is_subclass_of($transformClass, InteractiveTransformInterface::class)) {
                    $spec = $this->handleInteractiveTransform($transformClass, $askForInput);
                    if ($spec !== null) {
                        $transforms[] = $spec;
                    }

                    continue;
                }
            }

            // Non-interactive transform, add as-is
            $transforms[] = $transformKey;
        }

        return $transforms;
    }

    /**
     * Handle an interactive transform by collecting user input.
     *
     * @param  class-string<InteractiveTransformInterface>  $transformClass
     * @param  callable  $askForInput  (label, hint, examples, default) => string|null
     */
    private function handleInteractiveTransform(string $transformClass, callable $askForInput): ?string
    {
        $prompts = $transformClass::getPrompts();
        $responses = [];

        foreach ($prompts as $prompt) {
            $response = $askForInput(
                $prompt['label'] ?? 'Enter value',
                $prompt['hint'] ?? null,
                $prompt['examples'] ?? [],
                $prompt['default'] ?? null
            );

            if ($response === null) {
                return null; // User cancelled
            }

            $responses[] = $response;
        }

        return $transformClass::buildSpec($responses);
    }

    /**
     * Get the transform class for a given base key.
     *
     * @return class-string|null
     */
    private function getTransformClass(string $baseKey): ?string
    {
        return match ($baseKey) {
            'default' => DefaultTransform::class,
            'regex_replace' => RegexReplaceTransform::class,
            'parse_date' => ParseDateTransform::class,
            'date_format' => DateFormatTransform::class,
            'truncate' => TruncateTransform::class,
            'prefix' => PrefixTransform::class,
            'suffix' => SuffixTransform::class,
            'round' => RoundTransform::class,
            'multiply' => MultiplyTransform::class,
            'divide' => DivideTransform::class,
            'coalesce' => CoalesceTransform::class,
            'split' => SplitTransform::class,
            default => null,
        };
    }
}
