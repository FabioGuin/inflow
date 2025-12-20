<?php

namespace InFlow\ViewModels;

/**
 * View Model for flow run results display
 */
readonly class FlowRunViewModel
{
    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    public function __construct(
        public string $title,
        public string $status,
        public string $statusIcon,
        public int $importedRows,
        public int $skippedRows,
        public int $errorCount,
        public int $totalRows,
        public ?float $duration,
        public ?float $successRate,
        public array $errors,
        public string $completionMessage,
    ) {}
}
