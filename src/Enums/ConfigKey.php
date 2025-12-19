<?php

namespace InFlow\Enums;

enum ConfigKey: string
{
    case Sanitize = 'sanitize';
    case Output = 'output';
    case NewlineFormat = 'newline-format';
    case Preview = 'preview';
    case Mapping = 'mapping';

    /**
     * Get the corresponding config file key for this option key.
     *
     * Maps command option keys to their config file equivalents.
     * Some keys are the same, others are different (e.g., 'newline-format' -> 'newline_format').
     *
     * @return string The config file key
     */
    public function toConfigKey(): string
    {
        return match ($this) {
            self::NewlineFormat => 'newline_format',
            default => $this->value,
        };
    }

    /**
     * Get all available config keys.
     *
     * @return array<string> Array of all config key values
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
