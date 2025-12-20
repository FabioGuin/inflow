<?php

namespace InFlow\ViewModels;

/**
 * View Model for processing summary display
 */
readonly class SummaryViewModel
{
    /**
     * @param  array<string>  $headers
     * @param  array<int, array<int, string>>  $tableData
     */
    public function __construct(
        public string $title,
        public array $headers,
        public array $tableData,
        public string $completionMessage,
    ) {}
}
