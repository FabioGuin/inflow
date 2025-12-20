<?php

namespace InFlow\Enums\Data;

/**
 * Enum for custom mapping actions.
 */
enum CustomMappingAction: string
{
    case Manual = 'manual';
    case Field = 'field';
    case Relation = 'relation';
    case Skip = 'skip';
    case Back = 'back';

    /**
     * Get label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Enter field name manually',
            self::Field => 'Choose from available fields',
            self::Relation => 'Choose from available relations',
            self::Skip => 'Skip this column',
            self::Back => '← Go back to previous column',
        };
    }

    /**
     * Get action options based on available relations and history.
     *
     * @param  int  $relationCount  Number of available relations
     * @param  int  $fieldCount  Number of available fields
     * @param  bool  $hasPrevious  Whether there are previous mappings
     * @return array<string, string> Array of action => label
     */
    public static function options(int $relationCount = 0, int $fieldCount = 0, bool $hasPrevious = false): array
    {
        $options = [
            self::Manual->value => self::Manual->label(),
        ];

        if ($fieldCount > 0) {
            $options[self::Field->value] = self::Field->label().' ('.$fieldCount.' available)';
        }

        if ($relationCount > 0) {
            $options[self::Relation->value] = self::Relation->label().' ('.$relationCount.' available)';
        }

        $options[self::Skip->value] = self::Skip->label();

        if ($hasPrevious) {
            $options[self::Back->value] = self::Back->label();
        }

        return $options;
    }
}

