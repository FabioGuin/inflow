<?php

namespace InFlow\ViewModels;

/**
 * View Model for progress information with metrics
 */
readonly class ProgressInfoViewModel
{
    public function __construct(
        public string $message,
        public ?int $lines = null,
        public ?int $bytes = null,
        public ?int $rows = null,
        public ?int $columns = null,
    ) {}
}
