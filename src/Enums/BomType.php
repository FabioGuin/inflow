<?php

namespace InFlow\Enums;

/**
 * Enum for Byte Order Mark (BOM) types.
 *
 * BOM markers are special sequences at the beginning of files
 * that indicate the encoding format.
 */
enum BomType: string
{
    case Utf8 = 'utf8';
    case Utf16Le = 'utf16le';
    case Utf16Be = 'utf16be';

    /**
     * Get the BOM marker bytes for this type.
     *
     * @return string The BOM marker as a binary string
     */
    public function getMarker(): string
    {
        return match ($this) {
            self::Utf8 => "\xEF\xBB\xBF",
            self::Utf16Le => "\xFF\xFE",
            self::Utf16Be => "\xFE\xFF",
        };
    }

    /**
     * Get the byte length of this BOM marker.
     *
     * @return int The number of bytes in the BOM marker
     */
    public function getLength(): int
    {
        return match ($this) {
            self::Utf8 => 3,
            self::Utf16Le, self::Utf16Be => 2,
        };
    }

    /**
     * Get a human-readable name for this BOM type.
     *
     * @return string The display name
     */
    public function getName(): string
    {
        return match ($this) {
            self::Utf8 => 'UTF-8',
            self::Utf16Le => 'UTF-16 LE',
            self::Utf16Be => 'UTF-16 BE',
        };
    }

    /**
     * Detect which BOM type (if any) is present at the start of content.
     *
     * @param  string  $content  The content to check
     * @return BomType|null The detected BOM type, or null if none found
     */
    public static function detect(string $content): ?self
    {
        foreach (self::cases() as $bomType) {
            if (str_starts_with($content, $bomType->getMarker())) {
                return $bomType;
            }
        }

        return null;
    }
}
