<?php

namespace InFlow\Commands\Traits\File;

use InFlow\Services\File\FileSelectionService;

/**
 * Trait for handling file selection operations.
 *
 * Separates business logic (file discovery) from presentation (prompts, errors).
 * Uses FileSelectionService for file discovery and HandlesOutput trait methods
 * for user interaction with automatic fallback handling.
 */
trait HandlesFileSelection
{
    /**
     * Get file path from argument or prompt (FROM - source).
     *
     * Separates business logic (file discovery) from presentation (prompts, errors).
     * Uses FileSelectionService for file discovery and HandlesOutput trait methods
     * for user interaction with automatic fallback handling.
     *
     * @return string|null File path if provided/selected, null otherwise
     */
    private function getFilePath(): ?string
    {
        $filePath = $this->argument('from');
        if ($filePath !== null) {
            return $filePath;
        }

        // Non-interactive mode: cannot prompt
        if ($this->option('no-interaction')) {
            $this->error('File path is required when running in non-interactive mode.');

            return null;
        }

        // Business logic: discover available files
        $availableFiles = $this->fileSelectionService->findAvailableFiles();

        // Presentation: prompt user for file selection
        if (! empty($availableFiles)) {
            $filePath = $this->selectFileFromList($availableFiles);
            if ($filePath !== null) {
                return $filePath;
            }
        }

        // Fallback: prompt for manual file path entry
        return $this->promptForFilePath();
    }

    /**
     * Prompt user to select a file from available files list.
     *
     * @param  array<string, string>  $availableFiles  Array of [path => display_name]
     * @return string|null Selected file path, or null if cancelled
     */
    private function selectFileFromList(array $availableFiles): ?string
    {
        $selected = $this->selectWithFallback(
            label: 'Select file to process',
            options: $availableFiles
        );

        return $selected;
    }

    /**
     * Prompt user to enter file path manually.
     *
     * @return string|null Entered file path, or null if cancelled/validation fails
     */
    private function promptForFilePath(): ?string
    {
        $filePath = $this->textWithValidation(
            label: 'Enter file path',
            required: true,
            validate: fn ($value) => $this->fileSelectionService->isValidFile($value)
                ? null
                : 'File does not exist or is not readable.'
        );

        if ($filePath === null) {
            $this->error('File path is required.');
        }

        return $filePath;
    }

    /**
     * Require file path, throwing an exception if not provided
     *
     * @throws \RuntimeException If file path is not provided
     */
    private function requireFilePath(): string
    {
        $filePath = $this->getFilePath();

        if (! $filePath) {
            throw new \RuntimeException('File path is required.');
        }

        return $filePath;
    }
}
