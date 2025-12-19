<?php

namespace InFlow\Services\Formatter;

use InFlow\Enums\Delimiter;
use InFlow\ValueObjects\DetectedFormat;

/**
 * Service for formatting format information for display.
 *
 * Handles the business logic of formatting format data for presentation.
 * Presentation logic (actual output) is handled by the caller.
 */
class FormatFormatterService
{
    /**
     * Format delimiter for display.
     *
     * @param  string|null  $delimiter  The delimiter character
     * @return string The formatted delimiter display name
     */
    public function formatDelimiter(?string $delimiter): string
    {
        if ($delimiter === null) {
            return '<fg=gray>N/A</>';
        }

        $delimiterEnum = Delimiter::fromCharacter($delimiter);

        return $delimiterEnum?->getDisplayName() ?? $delimiter;
    }

    /**
     * Format boolean value for display.
     *
     * @param  bool  $value  The boolean value
     * @return string The formatted boolean display (Y/N with colors)
     */
    public function formatBoolean(bool $value): string
    {
        return $value ? '<fg=green>Yes</>' : '<fg=red>No</>';
    }

    /**
     * Format format information for table display.
     *
     * @param  DetectedFormat  $format  The detected format
     * @return array<int, array{property: string, value: string}> Array of [property, value] pairs
     */
    public function formatForTable(DetectedFormat $format): array
    {
        $rows = [
            [
                'property' => 'Type',
                'value' => "<fg=yellow>{$format->type->value}</>",
            ],
        ];

        // Only show delimiter/quote for formats that use them
        if (! $format->type->isXml()) {
            $rows[] = [
                'property' => 'Delimiter',
                'value' => "<fg=yellow>{$this->formatDelimiter($format->delimiter)}</>",
            ];
            $rows[] = [
                'property' => 'Quote Character',
                'value' => '<fg=yellow>'.($format->quoteChar ?? 'N/A').'</>',
            ];
        }

        $rows[] = [
            'property' => 'Has Header',
            'value' => $this->formatBoolean($format->hasHeader),
        ];
        $rows[] = [
            'property' => 'Encoding',
            'value' => "<fg=yellow>{$format->encoding}</>",
        ];

        return $rows;
    }
}
