<?php

namespace InFlow\Enums;

/**
 * Enum for ANSI escape codes used in terminal output.
 */
enum AnsiEscapeCode: string
{
    case MoveUp = "\033[1A";
    case ClearLine = "\033[K";

    /**
     * Get all ANSI escape codes as array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string is a valid ANSI escape code.
     */
    public static function isValid(string $code): bool
    {
        return in_array($code, self::values(), true);
    }
}
