<?php

namespace InFlow\Services\Formatter;

use InFlow\Constants\DisplayConstants;
use InFlow\Enums\MappingSuggestionType;

/**
 * Service for formatting mapping information for display.
 *
 * Handles the business logic of formatting mapping suggestions and alternatives for presentation.
 * Presentation logic (actual output) is handled by the caller.
 */
class MappingFormatterService
{
    /**
     * Format mapping alternatives for display.
     *
     * Separates direct fields from relations and limits the display to avoid overwhelming output.
     *
     * @param  array<string>  $alternatives  Alternative mapping paths
     * @return array{fields: array<string>, relations: array<string>, fields_display: string|null, relations_display: string|null}
     */
    public function formatAlternatives(array $alternatives): array
    {
        $directFields = [];
        $relationNames = [];

        foreach ($alternatives as $alt) {
            if (str_contains($alt, '.')) {
                [$relationName] = explode('.', $alt, 2);
                if (! in_array($relationName, $relationNames, true)) {
                    $relationNames[] = $relationName;
                }
            } else {
                $directFields[] = $alt;
            }
        }

        return [
            'fields' => $directFields,
            'relations' => $relationNames,
            'fields_display' => $this->formatFieldsForDisplay($directFields),
            'relations_display' => $this->formatRelationsForDisplay($relationNames),
        ];
    }

    /**
     * Format fields list for display with limit.
     *
     * @param  array<string>  $fields  List of field names
     * @return string|null Formatted fields string or null if empty
     */
    private function formatFieldsForDisplay(array $fields): ?string
    {
        if (empty($fields)) {
            return null;
        }

        $maxFields = DisplayConstants::MAX_ALTERNATIVE_FIELDS;
        $displayFields = array_slice($fields, 0, $maxFields);
        $fieldsDisplay = implode(', ', $displayFields);

        if (count($fields) > $maxFields) {
            $remaining = count($fields) - $maxFields;
            $fieldsDisplay .= ' <fg=gray>(+'.$remaining.' more)</>';
        }

        return $fieldsDisplay;
    }

    /**
     * Format relations list for display with limit.
     *
     * @param  array<string>  $relations  List of relation names
     * @return string|null Formatted relations string or null if empty
     */
    private function formatRelationsForDisplay(array $relations): ?string
    {
        if (empty($relations)) {
            return null;
        }

        $maxRelations = DisplayConstants::MAX_ALTERNATIVE_RELATIONS;
        $displayRelations = array_slice($relations, 0, $maxRelations);
        $relationsDisplay = implode(', ', array_map(fn ($name) => "<fg=magenta>{$name}</>", $displayRelations));

        if (count($relations) > $maxRelations) {
            $remaining = count($relations) - $maxRelations;
            $relationsDisplay .= ' <fg=gray>(+'.$remaining.' more)</>';
        }

        return $relationsDisplay;
    }

    /**
     * Format confidence percentage for display.
     *
     * @param  float  $confidence  Confidence level (0-1)
     * @return string Formatted confidence percentage
     */
    public function formatConfidence(float $confidence): string
    {
        return number_format($confidence * 100, 1);
    }

    /**
     * Get formatted suggestion type label.
     *
     * @param  bool  $isRelation  Whether this is a relation mapping
     * @return string Formatted label
     */
    public function getSuggestionTypeLabel(bool $isRelation): string
    {
        $type = $isRelation ? MappingSuggestionType::Relation : MappingSuggestionType::Field;

        return $type->formattedLabel();
    }
}
