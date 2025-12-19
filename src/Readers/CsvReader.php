<?php

namespace InFlow\Readers;

use InFlow\Contracts\ReaderInterface;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\DetectedFormat;

/**
 * CSV reader with streaming support for large files
 */
class CsvReader implements ReaderInterface
{
    private $stream;

    private ?array $headers = null;

    private ?array $currentRow = null;

    private int $currentLineNumber = 0;

    private int $key = -1;

    private bool $initialized = false;

    public function __construct(
        private readonly FileSource $source,
        private readonly DetectedFormat $format
    ) {
        $this->stream = $source->stream();
    }

    /**
     * Initialize reader and load headers if present
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        if ($this->format->hasHeader) {
            $headerLine = fgets($this->stream);
            if ($headerLine !== false) {
                $this->headers = $this->parseLine(rtrim($headerLine, "\r\n"));
            }
        }

        $this->initialized = true;
    }

    /**
     * Get current row as associative array
     */
    public function current(): array
    {
        if ($this->currentRow === null) {
            $this->next();
        }

        return $this->currentRow ?? [];
    }

    /**
     * Move to next row
     */
    public function next(): void
    {
        $this->initialize();

        // Loop until we find a non-empty row or reach EOF
        while (true) {
            $line = fgets($this->stream);

            if ($line === false) {
                $this->currentRow = null;

                return;
            }

            $this->currentLineNumber++;

            // Trim line and check if it's empty
            $trimmedLine = rtrim($line, "\r\n");
            if (empty($trimmedLine)) {
                // Skip empty lines and continue to next iteration
                continue;
            }

            $parsed = $this->parseLine($trimmedLine);

            // Check if parsed line is empty (all fields are empty)
            if (empty($parsed) || (count($parsed) === 1 && empty(trim($parsed[0] ?? '')))) {
                // Skip empty rows and continue to next iteration
                continue;
            }

            // Found a non-empty row, process it
            if ($this->headers !== null) {
                // Map to associative array using headers
                $this->currentRow = [];
                foreach ($this->headers as $index => $header) {
                    $this->currentRow[$header] = $parsed[$index] ?? null;
                }
            } else {
                // Use numeric indices
                $this->currentRow = $parsed;
            }

            $this->key++;
            break; // Exit loop after processing non-empty row
        }
    }

    /**
     * Get current key (row number)
     */
    public function key(): int
    {
        return $this->key;
    }

    /**
     * Check if current position is valid
     */
    public function valid(): bool
    {
        // If currentRow is null, try to read next line
        if ($this->currentRow === null) {
            $this->next();
        }

        return $this->currentRow !== null;
    }

    /**
     * Rewind to beginning
     */
    public function rewind(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = $this->source->stream();
        $this->currentRow = null;
        $this->currentLineNumber = 0;
        $this->key = -1;
        $this->headers = null;
        $this->initialized = false;
    }

    /**
     * Parse a CSV line with delimiter and quote handling
     */
    private function parseLine(string $line): array
    {
        if (empty($line)) {
            return [];
        }

        $delimiter = $this->format->delimiter;
        $quoteChar = $this->format->quoteChar;

        $fields = [];
        $currentField = '';
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            $nextChar = $line[$i + 1] ?? null;

            if ($char === $quoteChar) {
                if ($inQuotes && $nextChar === $quoteChar) {
                    // Escaped quote
                    $currentField .= $quoteChar;
                    $i++; // Skip next quote
                } else {
                    // Toggle quote state
                    $inQuotes = ! $inQuotes;
                }
            } elseif ($char === $delimiter && ! $inQuotes) {
                // Field separator
                $fields[] = trim($currentField);
                $currentField = '';
            } else {
                $currentField .= $char;
            }
        }

        // Add last field
        $fields[] = trim($currentField);

        return $fields;
    }

    /**
     * Get headers if available
     */
    public function getHeaders(): ?array
    {
        $this->initialize();

        return $this->headers;
    }

    /**
     * Close the stream
     */
    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
