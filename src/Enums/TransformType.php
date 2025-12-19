<?php

namespace InFlow\Enums;

/**
 * Enum for transformation types
 */
enum TransformType: string
{
    // String transforms
    case Trim = 'trim';
    case Upper = 'upper';
    case Lower = 'lower';
    case Capitalize = 'capitalize';
    case Title = 'title';
    case Slugify = 'slugify';
    case SnakeCase = 'snake_case';
    case CamelCase = 'camel_case';
    case StripTags = 'strip_tags';
    case Truncate = 'truncate';
    case Prefix = 'prefix';
    case Suffix = 'suffix';
    case CleanWhitespace = 'clean_whitespace';
    case NormalizeMultiline = 'normalize_multiline';

    // Numeric transforms
    case Round = 'round';
    case Floor = 'floor';
    case Ceil = 'ceil';
    case Multiply = 'multiply';
    case Divide = 'divide';
    case ToCents = 'to_cents';
    case FromCents = 'from_cents';

    // Date transforms
    case DateFormat = 'date_format';
    case ParseDate = 'parse_date';
    case Timestamp = 'timestamp';

    // Utility transforms
    case Cast = 'cast';
    case Concat = 'concat';
    case Default = 'default';
    case Coalesce = 'coalesce';
    case NullIfEmpty = 'null_if_empty';
    case JsonDecode = 'json_decode';
    case Split = 'split';
    case RegexReplace = 'regex_replace';

    /**
     * Get all transform types as array
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string is a valid transform type
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::values(), true);
    }
}
