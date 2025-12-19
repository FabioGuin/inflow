<?php

namespace InFlow\Sanitizers;

/**
 * Display labels for sanitizer statistics keys.
 *
 * Maps statistics keys to human-readable labels for display purposes.
 */
final class SanitizerStatLabels
{
    public const string BomRemoved = 'BOM markers removed';
    public const string BomBytesRemoved = 'BOM bytes removed';
    public const string NewlinesNormalized = 'Newlines normalized';
    public const string ControlCharsRemoved = 'Control characters removed';
    public const string EofFixed = 'EOF fixes applied';

    /**
     * Get the label for a statistics key.
     *
     * @param  string  $key  The statistics key
     */
    public static function for(string $key): string
    {
        return match ($key) {
            SanitizerStatisticsKeys::BomRemoved => self::BomRemoved,
            SanitizerStatisticsKeys::BomBytesRemoved => self::BomBytesRemoved,
            SanitizerStatisticsKeys::NewlinesNormalized => self::NewlinesNormalized,
            SanitizerStatisticsKeys::ControlCharsRemoved => self::ControlCharsRemoved,
            SanitizerStatisticsKeys::EofFixed => self::EofFixed,
            default => ucfirst(str_replace('_', ' ', $key)),
        };
    }
}
