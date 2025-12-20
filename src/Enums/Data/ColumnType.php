<?php

namespace InFlow\Enums\Data;

/**
 * Enum for column data types
 */
enum ColumnType: string
{
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Date = 'date';
    case Bool = 'bool';
    case Email = 'email';
    case Url = 'url';
    case Phone = 'phone';
    case Ip = 'ip';
    case Uuid = 'uuid';

    /**
     * Get all column types as array
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string is a valid column type
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::values(), true);
    }

    /**
     * Get the corresponding cast type string for this column type.
     *
     * Returns the cast type that should be used for transform suggestions.
     * Only numeric, date, and bool types have corresponding cast types.
     *
     * @return string|null The cast type (e.g., 'int', 'float', 'date', 'bool') or null if no cast type
     */
    public function toCastType(): ?string
    {
        return match ($this) {
            self::Int => 'int',
            self::Float => 'float',
            self::Date => 'date',
            self::Bool => 'bool',
            default => null,
        };
    }
}
