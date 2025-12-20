<?php

namespace InFlow\Services\File;

use InFlow\Enums\File\FileType;
use InFlow\Enums\File\StorageDirectory;

/**
 * Service for finding and selecting files from common directories.
 *
 * Handles the business logic of discovering available files in standard
 * Laravel storage directories. Presentation logic (prompts, display) is
 * handled by the caller.
 */
class FileSelectionService
{
    /**
     * Find available files in common directories.
     *
     * Searches through standard Laravel storage directories for files
     * with supported extensions. Returns an associative array where keys
     * are absolute file paths and values are human-readable display names.
     *
     * @return array<string, string> Array of [absolute_path => display_name]
     */
    public function findAvailableFiles(): array
    {
        $files = [];

        foreach (StorageDirectory::defaultDirectories() as $storageDir) {
            $absoluteDir = $storageDir->getAbsolutePath();
            if (! is_dir($absoluteDir)) {
                continue;
            }

            $foundFiles = $this->scanDirectory($absoluteDir);
            $files = array_merge($files, $foundFiles);
        }

        return $files;
    }

    /**
     * Validate that a file path exists and is readable.
     *
     * @param  string  $filePath  The file path to validate
     * @return bool True if file exists and is readable, false otherwise
     */
    public function isValidFile(string $filePath): bool
    {
        return file_exists($filePath)
            && is_file($filePath)
            && is_readable($filePath);
    }

    /**
     * Scan a directory for supported files.
     *
     * @param  string  $directory  Absolute directory path
     * @return array<string, string> Array of [absolute_path => display_name]
     */
    private function scanDirectory(string $directory): array
    {
        $files = [];
        $extensions = implode(',', FileType::values());
        $pattern = $directory.'/*.{'.$extensions.'}';
        $found = glob($pattern, GLOB_BRACE);

        if (! $found) {
            return $files;
        }

        foreach ($found as $file) {
            if (! $this->isValidFile($file)) {
                continue;
            }

            $relativePath = $this->getRelativePath($file);
            $fileSize = filesize($file);
            $files[$file] = $relativePath.' ('.number_format($fileSize).' bytes)';
        }

        return $files;
    }

    /**
     * Get relative path from base path for display purposes.
     *
     * @param  string  $absolutePath  Absolute file path
     * @return string Relative path from base_path()
     */
    private function getRelativePath(string $absolutePath): string
    {
        $basePath = base_path().'/';

        return str_replace($basePath, '', $absolutePath);
    }
}
