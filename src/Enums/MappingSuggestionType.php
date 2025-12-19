<?php

namespace InFlow\Enums;

/**
 * Enum for mapping suggestion types.
 */
enum MappingSuggestionType: string
{
    case Field = 'field';
    case Relation = 'relation';

    /**
     * Get display label for the suggestion type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Field => '[Field]',
            self::Relation => '[Relation]',
        };
    }

    /**
     * Get formatted display with color for the suggestion type.
     */
    public function formattedLabel(): string
    {
        return match ($this) {
            self::Field => '<fg=blue>[Field]</>',
            self::Relation => '<fg=magenta>[Relation]</>',
        };
    }
}
