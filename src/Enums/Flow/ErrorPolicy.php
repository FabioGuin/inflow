<?php

namespace InFlow\Enums\Flow;

/**
 * Enum for error handling policies in flow execution.
 */
enum ErrorPolicy: string
{
    case Stop = 'stop';
    case Continue = 'continue';

    /**
     * Get all error policies as array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string is a valid error policy.
     */
    public static function isValid(string $policy): bool
    {
        return in_array($policy, self::values(), true);
    }
}
