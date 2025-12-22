<?php

namespace InFlow\ViewModels;

/**
 * View Model for file information display
 */
readonly class FileInfoViewModel
{
    public function __construct(
        public string $name,
        public ?string $extension,
        public string $size, // Already formatted
        public ?string $mimeType,
    ) {}
}
