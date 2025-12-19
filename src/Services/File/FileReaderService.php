<?php

namespace InFlow\Services\File;

use InFlow\Sources\FileSource;

/**
 * Service for reading file content from FileSource.
 *
 * Handles the business logic of reading file content, supporting both
 * chunked reading for large files and direct reading for small files.
 * Presentation logic (progress bars, output) is handled by the caller.
 */
class FileReaderService
{
    /**
     * Threshold in bytes for using chunked reading (default: 10KB).
     * Files larger than this will be read in chunks.
     */
    private const CHUNKED_READING_THRESHOLD = 10240;

    /**
     * Chunk size in bytes for reading large files (default: 8KB).
     */
    private const CHUNK_SIZE = 8192;

    /**
     * Read file content from a FileSource.
     *
     * For large files (>10KB), reads in chunks and calls the progress callback
     * after each chunk. For small files, reads the entire content at once.
     *
     * @param  FileSource  $source  The file source to read from
     * @param  callable|null  $onProgress  Optional callback called during chunked reading.
     *                                     Receives (int $bytesRead, int $totalBytes, int $lineCount, int $chunkSize)
     * @return string The file content
     *
     * @throws \RuntimeException If file reading fails
     */
    public function read(FileSource $source, ?callable $onProgress = null): string
    {
        $stream = $source->stream();
        $fileSize = $source->getSize();

        try {
            if ($fileSize > self::CHUNKED_READING_THRESHOLD && $onProgress !== null) {
                $content = $this->readChunked($stream, $fileSize, $onProgress);
            } else {
                $content = stream_get_contents($stream);
            }

            if ($content === false) {
                throw new \RuntimeException("Failed to read file content from: {$source->getPath()}");
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Read file content in chunks with progress reporting.
     *
     * @param  resource  $stream  The file stream
     * @param  int  $fileSize  Total file size in bytes
     * @param  callable  $onProgress  Callback called after each chunk: (bytesRead, totalBytes, lineCount, chunkSize)
     * @return string The complete file content
     */
    private function readChunked($stream, int $fileSize, callable $onProgress): string
    {
        $content = '';
        $bytesRead = 0;
        $lineCount = 0;

        while (! feof($stream)) {
            $chunk = fread($stream, self::CHUNK_SIZE);
            if ($chunk === false) {
                break;
            }

            $chunkSize = strlen($chunk);
            $content .= $chunk;
            $bytesRead += $chunkSize;

            // Count newlines in chunk (handles LF, CRLF, CR)
            $lineCount += substr_count($chunk, "\n");
            $lineCount += substr_count($chunk, "\r");
            $lineCount -= substr_count($chunk, "\r\n"); // Avoid double counting CRLF

            // Report progress to caller (includes chunkSize for progress bar advance)
            $onProgress($bytesRead, $fileSize, $lineCount, $chunkSize);
        }

        return $content;
    }
}
