<?php

namespace InFlow\ViewModels;

/**
 * View Model for data preview display
 */
readonly class PreviewViewModel
{
    /**
     * @param  array<string>|null  $headers
     * @param  array<int, array<int, mixed>>  $tableData
     * @param  array<int, array<string, mixed>>|null  $rawRows
     */
    public function __construct(
        public string $title,
        public ?array $headers,
        public array $tableData,
        public ?array $rawRows = null,
    ) {}
}
