<?php

namespace InFlow\Enums\File;

/**
 * Enum for newline formats
 */
enum NewlineFormat: string
{
    case Lf = 'lf';
    case Crlf = 'crlf';
    case Cr = 'cr';

    /**
     * Get the actual newline character(s) for this format
     */
    public function getCharacter(): string
    {
        return match ($this) {
            self::Lf => "\n",
            self::Crlf => "\r\n",
            self::Cr => "\r",
        };
    }

    /**
     * Get all newline formats as array
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string is a valid newline format
     */
    public static function isValid(string $format): bool
    {
        return in_array($format, self::values(), true);
    }

    /**
     * Get display name for this format.
     *
     * @return string The display name (LF, CRLF, or CR)
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::Lf => 'LF',
            self::Crlf => 'CRLF',
            self::Cr => 'CR',
        };
    }

    /**
     * Get NewlineFormat enum from character.
     *
     * @param  string  $character  The newline character(s)
     * @return self|null The corresponding enum or null if not found
     */
    public static function fromCharacter(string $character): ?self
    {
        return match ($character) {
            "\n" => self::Lf,
            "\r\n" => self::Crlf,
            "\r" => self::Cr,
            default => null,
        };
    }
}

