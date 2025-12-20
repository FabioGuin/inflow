<?php

namespace InFlow\ValueObjects\File;

use InFlow\Enums\File\FileType;

/**
 * Value Object representing the detected format of a file
 */
readonly class DetectedFormat
{
    public function __construct(
        public FileType $type,
        public ?string $delimiter,
        public ?string $quoteChar,
        public bool $hasHeader,
        public string $encoding
    ) {}

    /**
     * Checks if the format is valid
     */
    public function isValid(): bool
    {
        if (! FileType::isValid($this->type->value)) {
            return false;
        }

        // XML doesn't need delimiter/quoteChar
        if ($this->type->isXml()) {
            return ! empty($this->encoding);
        }

        // Other formats require delimiter
        return ! empty($this->delimiter) && ! empty($this->encoding);
    }

    /**
     * Returns the format as an array
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'delimiter' => $this->delimiter,
            'quote_char' => $this->quoteChar,
            'has_header' => $this->hasHeader,
            'encoding' => $this->encoding,
        ];
    }
}

