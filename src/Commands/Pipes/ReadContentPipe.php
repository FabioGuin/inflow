<?php

namespace InFlow\Commands\Pipes;

use Closure;
use InFlow\Commands\InFlowCommandContext;
use InFlow\Contracts\ProcessingPipeInterface;
use InFlow\Services\DataProcessing\ContentUtilityService;
use InFlow\Services\File\FileReaderService;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\ProcessingContext;

use function Laravel\Prompts\progress;

/**
 * Second step of the ETL pipeline: read raw file content into memory.
 *
 * Reads the entire file content from the FileSource and counts the number of lines.
 * For large files (>10KB), displays a progress bar during reading. The content is
 * stored in the ProcessingContext for subsequent processing steps (sanitization,
 * format detection, etc.).
 *
 * Any exceptions thrown during file reading will be caught and handled by the
 * exception handler in InFlowCommand::handle().
 */
readonly class ReadContentPipe implements ProcessingPipeInterface
{
    /**
     * Threshold in bytes for showing progress bar (10KB).
     */
    private const PROGRESS_THRESHOLD = 10240;

    public function __construct(
        private InFlowCommandContext $command,
        private FileReaderService $fileReader,
        private ContentUtilityService $contentUtility
    ) {}

    /**
     * Read file content and update processing context.
     *
     * Loads the entire file content into memory and counts lines. For large files,
     * shows a progress bar with real-time line count updates. The content and line
     * count are stored in the context for use by subsequent pipeline steps.
     *
     * @param  ProcessingContext  $context  The processing context containing the FileSource
     * @param  Closure  $next  The next pipe in the pipeline
     * @return ProcessingContext Updated context with file content and line count
     *
     * @throws \RuntimeException If file reading fails (handled by InFlowCommand::handle())
     */
    public function handle(ProcessingContext $context, Closure $next): ProcessingContext
    {
        if ($context->cancelled) {
            return $next($context);
        }

        // Skip if source is not available (should not happen, but defensive check)
        if ($context->source === null) {
            return $next($context);
        }

        $this->command->infoLine('<fg=blue>Step 2/9:</> <fg=gray>Reading file content...</>');

        // Read file content (with progress bar for large files)
        $content = $this->readFileContent($context->source);

        // Count lines in content (handles LF, CRLF, CR newline formats)
        $lineCount = $this->contentUtility->countLines($content);

        // Update context with content and line count for subsequent steps
        $context = $context
            ->withContent($content)
            ->withLineCount($lineCount);

        // Display summary of what was read
        $this->command->infoLine('<fg=green>✓ Content read:</> <fg=yellow>'.number_format($lineCount).'</> lines, <fg=yellow>'.number_format(strlen($content)).'</> bytes');

        // Checkpoint after content read
        $checkpointResult = $this->command->checkpoint('Content read', [
            'Lines' => number_format($lineCount),
            'Bytes' => number_format(strlen($content)),
        ]);

        if ($checkpointResult === 'cancel') {
            return $next($context->withCancelled());
        }

        return $next($context);
    }

    /**
     * Read file content from source with optional progress display.
     *
     * For large files (>10KB) in non-quiet mode, shows a progress bar with real-time
     * line count updates. For small files or quiet mode, reads without progress display.
     *
     * @param  FileSource  $source  The file source to read from
     * @return string The file content
     */
    private function readFileContent(FileSource $source): string
    {
        $fileSize = $source->getSize();

        // Show progress bar for large files in non-quiet mode
        if ($fileSize > self::PROGRESS_THRESHOLD && ! $this->command->isQuiet()) {
            return $this->readFileContentWithProgress($source, $fileSize);
        }

        // For small files or quiet mode, read without progress display
        return $this->fileReader->read($source);
    }

    /**
     * Read file content with progress bar display.
     *
     * Creates and manages the progress bar UI while delegating the actual reading
     * to FileReaderService.
     *
     * @param  FileSource  $source  The file source to read from
     * @param  int  $fileSize  Total file size in bytes
     * @return string The file content
     */
    private function readFileContentWithProgress(FileSource $source, int $fileSize): string
    {
        $progressBar = progress(
            label: 'Reading file',
            steps: $fileSize
        );

        $content = $this->fileReader->read(
            $source,
            function (int $bytesRead, int $totalBytes, int $lineCount, int $chunkSize) use ($progressBar) {
                // Update progress bar label with line count
                $progressBar->label('Reading file (lines: '.number_format($lineCount).')');
                // Advance by chunk size (not total bytes read)
                $progressBar->advance($chunkSize);
            }
        );

        $progressBar->finish();

        return $content;
    }
}
