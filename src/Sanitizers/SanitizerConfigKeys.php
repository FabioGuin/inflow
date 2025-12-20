<?php

namespace InFlow\Sanitizers;

/**
 * Configuration keys for sanitizer operations.
 *
 * Centralizes all configuration key names to avoid hardcoded strings.
 */
final class SanitizerConfigKeys
{
    public const string RemoveBom = 'remove_bom';

    public const string NormalizeNewlines = 'normalize_newlines';

    public const string NewlineFormat = 'newline_format';

    public const string RemoveControlChars = 'remove_control_chars';

    public const string HandleTruncatedEof = 'handle_truncated_eof';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::RemoveBom,
            self::NormalizeNewlines,
            self::NewlineFormat,
            self::RemoveControlChars,
            self::HandleTruncatedEof,
        ];
    }
}
