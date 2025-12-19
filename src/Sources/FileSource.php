<?php

namespace InFlow\Sources;

use InFlow\Contracts\SourceInterface;

readonly class FileSource implements SourceInterface
{
    private function __construct(
        private string $path,
        private string $name,
        private string $extension,
        private int $size,
        private ?string $mimeType = null
    ) {}

    /**
     * Create FileSource from file path
     */
    public static function fromPath(string $path): self
    {
        if (! file_exists($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }

        if (! is_readable($path)) {
            throw new \RuntimeException("File is not readable: {$path}");
        }

        $info = pathinfo($path);
        $size = filesize($path);

        if ($size === false) {
            throw new \RuntimeException("Unable to get file size: {$path}");
        }

        return new self(
            path: $path,
            name: $info['basename'] ?? basename($path),
            extension: $info['extension'] ?? '',
            size: $size,
            mimeType: mime_content_type($path) ?: null
        );
    }

    /**
     * Returns a readable stream of the source content
     */
    public function stream()
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open file for reading: {$this->path}");
        }

        return $handle;
    }

    /**
     * Get file name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get file extension
     */
    public function getExtension(): string
    {
        return $this->extension;
    }

    /**
     * Get file size in bytes
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Get MIME type
     */
    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    /**
     * Get file path
     */
    public function getPath(): string
    {
        return $this->path;
    }
}
