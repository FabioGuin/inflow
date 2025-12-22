<?php

namespace InFlow\Services\Formatter;

use InFlow\Services\File\FileWriterService;
use InFlow\Sources\FileSource;
use InFlow\ViewModels\FileInfoViewModel;

/**
 * Formatter for file information
 */
readonly class FileInfoFormatter
{
    public function __construct(
        private FileWriterService $fileWriter
    ) {}

    public function format(FileSource $source): FileInfoViewModel
    {
        return new FileInfoViewModel(
            name: $source->getName(),
            extension: $source->getExtension(),
            size: $this->fileWriter->formatSize($source->getSize()),
            mimeType: $source->getMimeType(),
        );
    }
}
