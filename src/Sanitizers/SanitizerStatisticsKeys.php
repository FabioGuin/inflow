<?php

namespace InFlow\Sanitizers;

/**
 * Statistics keys for sanitization reports.
 *
 * Centralizes all statistics key names to avoid hardcoded strings.
 */
final class SanitizerStatisticsKeys
{
    public const string BomRemoved = 'bom_removed';

    public const string BomBytesRemoved = 'bom_bytes_removed';

    public const string NewlinesNormalized = 'newlines_normalized';

    public const string ControlCharsRemoved = 'control_chars_removed';

    public const string EofFixed = 'eof_fixed';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::BomRemoved,
            self::BomBytesRemoved,
            self::NewlinesNormalized,
            self::ControlCharsRemoved,
            self::EofFixed,
        ];
    }
}
