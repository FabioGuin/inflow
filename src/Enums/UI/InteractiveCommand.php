<?php

namespace InFlow\Enums\UI;

/**
 * Interactive command responses for CLI prompts.
 *
 * Centralizes common user responses in interactive mode.
 */
enum InteractiveCommand: string
{
    case Back = 'back';
    case Skip = 'skip';

    /**
     * Check if a string value matches this command.
     *
     * @param  string|null  $value  The value to check
     * @return bool True if the value matches this command
     */
    public function matches(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return strtolower($value) === $this->value;
    }

    /**
     * Check if a value is a back command.
     *
     * @param  string|null  $value  The value to check
     * @return bool True if the value is 'back'
     */
    public static function isBack(?string $value): bool
    {
        return self::Back->matches($value);
    }
}

