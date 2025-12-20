<?php

namespace InFlow\Services\Formatter;

use InFlow\ViewModels\ColumnMappingInfoViewModel;

/**
 * Formatter for column mapping information
 */
readonly class ColumnMappingInfoFormatter
{
    public function format(
        string $sourceColumn,
        string $suggestedPath,
        float $confidence,
        array $alternatives,
        bool $isRelation
    ): ColumnMappingInfoViewModel {
        $fields = [];
        $relations = [];

        foreach ($alternatives as $alt) {
            if (is_string($alt)) {
                $path = $alt;
                $altIsRelation = str_contains($alt, '.');
            } else {
                $path = $alt['path'] ?? $alt;
                $altIsRelation = $alt['is_relation'] ?? str_contains($path, '.');
            }

            if ($altIsRelation) {
                $relations[] = $path;
            } else {
                $fields[] = $path;
            }
        }

        return new ColumnMappingInfoViewModel(
            sourceColumn: $sourceColumn,
            suggestedPath: $suggestedPath,
            confidence: $confidence,
            isRelation: $isRelation,
            fieldAlternatives: array_slice($fields, 0, 3),
            relationAlternatives: array_slice($relations, 0, 3),
        );
    }
}
