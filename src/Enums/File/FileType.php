<?php

namespace InFlow\Enums\File;

/**
 * Enum for file types
 */
enum FileType: string
{
    case Csv = 'csv';
    case Txt = 'txt';
    case Xls = 'xls';
    case Xlsx = 'xlsx';
    case Json = 'json';
    case Xml = 'xml';

    /**
     * Get all file types as array
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string is a valid file type
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::values(), true);
    }

    /**
     * Check if this is an Excel file type
     */
    public function isExcel(): bool
    {
        return $this === self::Xls || $this === self::Xlsx;
    }

    /**
     * Check if this is a CSV/TXT file type
     */
    public function isCsv(): bool
    {
        return $this === self::Csv || $this === self::Txt;
    }

    /**
     * Check if this is a JSON file type
     */
    public function isJson(): bool
    {
        return $this === self::Json;
    }

    /**
     * Check if this is an XML file type
     */
    public function isXml(): bool
    {
        return $this === self::Xml;
    }
}
