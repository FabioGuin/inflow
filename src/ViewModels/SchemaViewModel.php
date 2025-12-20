<?php

namespace InFlow\ViewModels;

/**
 * View Model for schema display
 */
readonly class SchemaViewModel
{
    /**
     * @param  array<int, array{name: string, type: string, nullPercent: float, examples: array<string>}>  $columns
     */
    public function __construct(
        public string $title,
        public array $columns,
    ) {}
}
