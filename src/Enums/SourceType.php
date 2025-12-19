<?php

namespace InFlow\Enums;

/**
 * Enum for source types in flow configuration.
 */
enum SourceType: string
{
    case File = 'file';

    /**
     * Get all source types as array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string is a valid source type.
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::values(), true);
    }
}
