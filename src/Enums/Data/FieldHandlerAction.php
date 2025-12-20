<?php

namespace InFlow\Enums\Data;

/**
 * Enum for field handler actions when handling missing required fields.
 */
enum FieldHandlerAction: string
{
    case Default = 'default';
    case Map = 'map';
    case Transform = 'transform';
    case Skip = 'skip';
    case Cancel = 'cancel';

    /**
     * Get label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::Default => 'Set a default value',
            self::Map => 'Map from source column',
            self::Transform => 'Generate from another field (e.g., slug from name)',
            self::Skip => 'Skip this field (will cause errors)',
            self::Cancel => '← Cancel and go back',
        };
    }

    /**
     * Get all action options as array.
     *
     * @return array<string, string> Array of action => label
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            if ($case !== self::Cancel) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }

    /**
     * Get action options with cancel option.
     *
     * @return array<string, string> Array of action => label including cancel
     */
    public static function optionsWithCancel(): array
    {
        $options = self::options();
        $options[self::Cancel->value] = self::Cancel->label();

        return $options;
    }
}

