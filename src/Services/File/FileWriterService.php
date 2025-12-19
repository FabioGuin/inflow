<?php

namespace InFlow\Services\File;

/**
 * Service for writing content to files.
 *
 * Handles the business logic of writing file content and formatting file sizes.
 * Presentation logic (displaying messages, errors) is handled by the caller.
 */
class FileWriterService
{
    /**
     * Write content to a file.
     *
     * @param  string  $path  The file path to write to
     * @param  string  $content  The content to write
     * @return int|false Number of bytes written, or false on failure
     */
    public function write(string $path, string $content): int|false
    {
        return file_put_contents($path, $content);
    }

    /**
     * Format file size in bytes to human-readable format.
     *
     * Formats sizes as: bytes, KB, or MB depending on the size.
     *
     * @param  int  $bytes  Size in bytes
     * @return string Formatted size string (e.g., "1.5 MB", "512 bytes")
     */
    public function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} bytes";
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return number_format($bytes / 1048576, 2).' MB';
    }
}
