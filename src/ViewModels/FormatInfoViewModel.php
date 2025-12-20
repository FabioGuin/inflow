<?php

namespace InFlow\ViewModels;

/**
 * View Model for format information display
 */
readonly class FormatInfoViewModel
{
    public function __construct(
        public string $title,
        public string $type,
        public ?string $delimiter,
        public ?string $encoding,
        public bool $hasHeader,
    ) {}
}
