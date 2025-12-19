<?php

namespace InFlow\Sanitizers;

/**
 * Standardized messages for sanitization operations.
 *
 * Centralizes all decision messages to avoid hardcoded strings.
 */
final class SanitizerMessages
{
    public const string BomRemoved = 'Removed %s BOM';
    public const string NewlinesNormalized = 'Normalized newlines to %s format';
    public const string ControlCharsRemoved = 'Removed %d control character(s)';
    public const string EofFixed = 'Added missing newline at EOF';
}
