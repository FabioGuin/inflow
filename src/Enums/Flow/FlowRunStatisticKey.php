<?php

namespace InFlow\Enums\Flow;

/**
 * Statistics keys for FlowRun progress display.
 *
 * Centralizes all statistics key names to avoid hardcoded strings.
 */
enum FlowRunStatisticKey: string
{
    case Imported = 'imported';
    case Skipped = 'skipped';
    case Errors = 'errors';
    case Progress = 'progress';

    /**
     * Get all statistic keys as array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string is a valid statistic key.
     */
    public static function isValid(string $key): bool
    {
        return in_array($key, self::values(), true);
    }
}
