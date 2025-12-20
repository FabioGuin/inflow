<?php

namespace InFlow\ViewModels;

/**
 * View Model for column mapping information display
 */
readonly class ColumnMappingInfoViewModel
{
    /**
     * @param  array<string>  $fieldAlternatives
     * @param  array<string>  $relationAlternatives
     */
    public function __construct(
        public string $sourceColumn,
        public string $suggestedPath,
        public float $confidence,
        public bool $isRelation,
        public array $fieldAlternatives,
        public array $relationAlternatives,
    ) {}
}
