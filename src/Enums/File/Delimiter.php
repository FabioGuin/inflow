<?php

namespace InFlow\Enums\File;

/**
 * Enum for common CSV delimiters
 */
enum Delimiter: string
{
    case Comma = ',';
    case Semicolon = ';';
    case Tab = "\t";
    case Pipe = '|';
    case Colon = ':';

    /**
     * Get display name for this delimiter.
     *
     * @return string The human-readable display name
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::Tab => 'TAB',
            self::Comma => 'Comma (,)',
            self::Semicolon => 'Semicolon (;)',
            self::Pipe => 'Pipe (|)',
            self::Colon => 'Colon (:)',
        };
    }

    /**
     * Get Delimiter enum from character.
     *
     * @param  string  $character  The delimiter character
     * @return self|null The corresponding enum or null if not found
     */
    public static function fromCharacter(string $character): ?self
    {
        return match ($character) {
            ',' => self::Comma,
            ';' => self::Semicolon,
            "\t" => self::Tab,
            '|' => self::Pipe,
            ':' => self::Colon,
            default => null,
        };
    }

    /**
     * Get all delimiter characters as array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
