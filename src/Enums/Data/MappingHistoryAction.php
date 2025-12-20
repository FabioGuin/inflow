<?php

namespace InFlow\Enums\Data;

/**
 * Enum for mapping history action types.
 *
 * Represents the different types of actions that can be performed
 * on a mapping entry in the history.
 */
enum MappingHistoryAction: string
{
    case Accepted = 'accepted';
    case Custom = 'custom';
    case Skipped = 'skipped';

    /**
     * Get the display symbol for the action.
     */
    public function getDisplaySymbol(): string
    {
        return match ($this) {
            self::Accepted => '✓',
            self::Custom => '→',
            self::Skipped => '⊘',
        };
    }
}

