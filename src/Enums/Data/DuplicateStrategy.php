<?php

namespace InFlow\Enums\Data;

/**
 * Enum for duplicate handling strategies in ETL flows.
 */
enum DuplicateStrategy: string
{
    case Error = 'error';
    case Skip = 'skip';
    case Update = 'update';

    /**
     * Get label for the strategy.
     */
    public function label(): string
    {
        return match ($this) {
            self::Error => 'Error: Stop on duplicate (default)',
            self::Skip => 'Skip: Ignore duplicate records',
            self::Update => 'Update: Update existing records',
        };
    }

    /**
     * Get all strategy options as array.
     *
     * @return array<string, string> Array of strategy => label
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Get strategy options with back option.
     *
     * @return array<string, string> Array of strategy => label including back
     */
    public static function optionsWithBack(): array
    {
        $options = self::options();
        $options['__back__'] = '← Go back to unique key selection';

        return $options;
    }
}
