<?php

namespace InFlow\Readers;

use InFlow\Contracts\ReaderInterface;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\File\DetectedFormat;

/**
 * JSON reader with support for both JSON Lines (NDJSON) and JSON arrays.
 *
 * Supports:
 * - JSON Lines: Each line is a separate JSON object
 * - JSON Array: A single array containing multiple objects [{ }, { }]
 */
class JsonLinesReader implements ReaderInterface
{
    private $stream;

    private ?array $currentRow = null;

    private int $currentLineNumber = 0;

    private int $key = -1;

    private bool $initialized = false;

    /** @var \Generator|null Generator for JSON array elements */
    private ?\Generator $arrayGenerator = null;

    /** @var bool Whether the file is a JSON array format */
    private bool $isJsonArray = false;

    public function __construct(
        private readonly FileSource $source,
        private readonly DetectedFormat $format
    ) {
        $this->stream = $source->stream();
    }

    /**
     * Initialize reader - detect format and prepare for iteration.
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        // Peek at the first non-whitespace character to detect format
        $this->detectJsonFormat();
    }

    /**
     * Detect if file is JSON array or JSON Lines format.
     */
    private function detectJsonFormat(): void
    {
        // Read enough content to find the first significant character
        $buffer = '';
        while (! feof($this->stream)) {
            $char = fgetc($this->stream);
            if ($char === false) {
                break;
            }
            $buffer .= $char;

            // Skip whitespace
            if (ctype_space($char)) {
                continue;
            }

            if ($char === '[') {
                // This is a JSON array - read the entire file and parse
                $this->isJsonArray = true;
                $this->loadJsonArray($buffer);

                return;
            }

            // Not an array, rewind and use line-by-line reading
            rewind($this->stream);

            return;
        }
    }

    /**
     * Create generator for JSON array elements.
     *
     * Note: JSON arrays must be fully parsed, but we use a generator
     * to iterate over elements for consistency and potential future optimizations.
     */
    private function loadJsonArray(string $startBuffer): void
    {
        // Read the rest of the file
        $content = $startBuffer.stream_get_contents($this->stream);

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \inflow_report(new \RuntimeException('Invalid JSON array: '.json_last_error_msg()), 'warning');
            $this->arrayGenerator = $this->generateArrayElements([]);

            return;
        }

        if (! is_array($decoded)) {
            $this->arrayGenerator = $this->generateArrayElements([]);

            return;
        }

        // Check if it's an indexed array (list of objects)
        if (array_is_list($decoded)) {
            $this->arrayGenerator = $this->generateArrayElements($decoded);
        } else {
            // Single object wrapped in array notation - treat as single row
            $this->arrayGenerator = $this->generateArrayElements([$decoded]);
        }
    }

    /**
     * Generate array elements using generator.
     *
     * @param  array<int, array<string, mixed>>  $data  The JSON array data
     * @return \Generator<array<string, mixed>>
     */
    private function generateArrayElements(array $data): \Generator
    {
        foreach ($data as $element) {
            if (is_array($element)) {
                yield $element;
            }
        }
    }

    /**
     * Get current row as associative array.
     */
    public function current(): array
    {
        if ($this->currentRow === null) {
            $this->next();
        }

        return $this->currentRow ?? [];
    }

    /**
     * Move to next row.
     */
    public function next(): void
    {
        $this->initialize();

        // Use array iteration for JSON array format
        if ($this->isJsonArray) {
            $this->nextFromArray();

            return;
        }

        // Use streaming for JSON Lines format
        $this->nextFromStream();
    }

    /**
     * Get next element from JSON array generator.
     */
    private function nextFromArray(): void
    {
        if ($this->arrayGenerator === null) {
            $this->currentRow = null;

            return;
        }

        if ($this->arrayGenerator->valid()) {
            $this->currentRow = $this->arrayGenerator->current();
            $this->arrayGenerator->next();
            $this->key++;
        } else {
            $this->currentRow = null;
        }
    }

    /**
     * Get next element from stream (JSON Lines format).
     */
    private function nextFromStream(): void
    {
        // Loop until we find a valid JSON object or reach EOF
        while (true) {
            $jsonBuffer = '';
            $braceCount = 0;
            $inString = false;
            $escapeNext = false;

            // Read lines until we have a complete JSON object
            while (true) {
                $line = fgets($this->stream);

                if ($line === false) {
                    // EOF reached
                    if (empty($jsonBuffer)) {
                        $this->currentRow = null;

                        return;
                    }
                    // Try to parse what we have
                    break;
                }

                $this->currentLineNumber++;

                // Process character by character to track braces and strings
                $lineLength = strlen($line);
                for ($i = 0; $i < $lineLength; $i++) {
                    $char = $line[$i];

                    if ($escapeNext) {
                        $jsonBuffer .= $char;
                        $escapeNext = false;

                        continue;
                    }

                    if ($char === '\\') {
                        $escapeNext = true;
                        $jsonBuffer .= $char;

                        continue;
                    }

                    if ($char === '"') {
                        $inString = ! $inString;
                        $jsonBuffer .= $char;

                        continue;
                    }

                    if (! $inString) {
                        if ($char === '{') {
                            $braceCount++;
                        } elseif ($char === '}') {
                            $braceCount--;
                        }
                    }

                    $jsonBuffer .= $char;
                }

                // If brace count is 0, we have a complete JSON object
                if ($braceCount === 0 && ! empty(trim($jsonBuffer))) {
                    break;
                }
            }

            // Try to parse the accumulated JSON
            $trimmedBuffer = trim($jsonBuffer);
            if (empty($trimmedBuffer)) {
                continue;
            }

            $decoded = json_decode($trimmedBuffer, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Skip invalid JSON (could log this in future)
                continue;
            }

            // Check if decoded result is an array (associative or indexed)
            if (! is_array($decoded)) {
                // Skip non-array JSON values (could be scalar values)
                continue;
            }

            // Found a valid JSON object/array, use it as current row
            $this->currentRow = $decoded;
            $this->key++;
            break; // Exit loop after processing valid row
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
     * Rewind to beginning.
     */
    public function rewind(): void
    {
        // For JSON array, recreate generator
        if ($this->isJsonArray) {
            // Re-initialize to recreate generator
            $this->initialized = false;
            $this->arrayGenerator = null;
            $this->currentRow = null;
            $this->key = -1;
            $this->initialize();

            return;
        }

        // For JSON Lines, reopen the stream
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = $this->source->stream();
        $this->currentRow = null;
        $this->currentLineNumber = 0;
        $this->key = -1;
        $this->initialized = false;
        $this->arrayGenerator = null;
        $this->isJsonArray = false;
    }

    /**
     * Get headers if available (extracted from first row keys)
     */
    public function getHeaders(): ?array
    {
        if ($this->currentRow === null) {
            $this->next();
        }

        if ($this->currentRow === null) {
            return null;
        }

        // Return keys from current row as headers
        return array_keys($this->currentRow);
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
