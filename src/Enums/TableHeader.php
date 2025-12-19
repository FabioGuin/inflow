<?php

namespace InFlow\Enums;

/**
 * Enum for table headers used throughout the application
 */
enum TableHeader: string
{
    // Schema table headers
    case Column = 'Column';
    case Type = 'Type';
    case NullEmpty = 'Null/Empty';
    case Unique = 'Unique';
    case Min = 'Min';
    case Max = 'Max';

    // General table headers
    case Action = 'Action';
    case Count = 'Count';
    case Property = 'Property';
    case Value = 'Value';

    /**
     * Get schema table headers in display order.
     *
     * @return array<string>
     */
    public static function schemaHeaders(): array
    {
        return [
            self::Column->value,
            self::Type->value,
            self::NullEmpty->value,
            self::Unique->value,
            self::Min->value,
            self::Max->value,
        ];
    }

    /**
     * Get info table headers (Property/Value).
     *
     * @return array<string>
     */
    public static function infoHeaders(): array
    {
        return [
            self::Property->value,
            self::Value->value,
        ];
    }

    /**
     * Get report table headers (Action/Count).
     *
     * @return array<string>
     */
    public static function reportHeaders(): array
    {
        return [
            self::Action->value,
            self::Count->value,
        ];
    }
}
