<?php

namespace InFlow\Commands\Pipes;

use Closure;
use InFlow\Commands\InFlowCommandContext;
use InFlow\Constants\DisplayConstants;
use InFlow\Contracts\ProcessingPipeInterface;
use InFlow\Enums\TableHeader;
use InFlow\Services\File\FileWriterService;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\ProcessingContext;

/**
 * First step of the ETL pipeline: load and validate the source file.
 *
 * Creates a FileSource instance from the file path, validates file existence
 * and readability. Any RuntimeException thrown by FileSource::fromPath() will
 * be caught and handled by the exception handler in InFlowCommand::handle().
 */
readonly class LoadFilePipe implements ProcessingPipeInterface
{
    public function __construct(
        private InFlowCommandContext $command,
        private FileWriterService $fileWriter
    ) {}

    /**
     * Load the source file and create a FileSource instance.
     *
     * Validates file existence, readability, and creates metadata.
     * Displays file information to the user (name, size, MIME type, etc.).
     *
     * @param  ProcessingContext  $context  The processing context containing the file path
     * @param  Closure  $next  The next pipe in the pipeline
     * @return ProcessingContext Updated context with FileSource instance
     *
     * @throws \RuntimeException If file is not found, not readable, or other file access errors
     */
    public function handle(ProcessingContext $context, Closure $next): ProcessingContext
    {
        $this->command->infoLine('<fg=blue>Step 1/9:</> <fg=gray>Loading file...</>');

        // Create FileSource instance (validates file existence and readability)
        // Exceptions are handled by InFlowCommand::handle() exception handler
        $source = FileSource::fromPath($context->filePath);
        $context = $context->withSource($source);

        $this->command->success('File loaded successfully');
        $this->displayFileInfo($source);

        // Checkpoint after file load
        $checkpointResult = $this->command->checkpoint('File loaded', [
            'Name' => $source->getName(),
            'Size' => $this->fileWriter->formatSize($source->getSize()),
        ]);

        if ($checkpointResult === 'cancel') {
            return $next($context->withCancelled());
        }

        return $next($context);
    }

    /**
     * Display file information to the user.
     *
     * Shows file name, extension, size, and MIME type in a formatted table.
     * Automatically skipped in quiet mode.
     *
     * @param  FileSource  $source  The file source to display information for
     */
    private function displayFileInfo(FileSource $source): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->newLine();
        $this->command->infoLine('File Information');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        $sizeFormatted = $this->fileWriter->formatSize($source->getSize());

        $this->command->table(
            TableHeader::infoHeaders(),
            [
                ['Name', $source->getName()],
                ['Extension', $source->getExtension() ?: '<fg=gray>none</>'],
                ['Size', $sizeFormatted],
                ['MIME Type', $source->getMimeType() ?: '<fg=gray>unknown</>'],
            ]
        );
        $this->command->newLine();
    }
}
