<?php

namespace InFlow\Detectors;

use InFlow\Enums\FileType;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\DetectedFormat;

/**
 * Detects file format and parameters (delimiter, quote char, header, encoding)
 */
class FormatDetector
{
    /**
     * Common delimiters to test
     */
    private const DELIMITERS = [',', ';', "\t", '|', ':'];

    /**
     * Common quote characters
     */
    private const QUOTE_CHARS = ['"', "'"];

    /**
     * Number of lines to sample for detection
     */
    private const SAMPLE_LINES = 10;

    /**
     * Detect format from FileSource
     */
    public function detect(FileSource $source): DetectedFormat
    {
        $stream = $source->stream();
        $extension = strtolower($source->getExtension());

        // Read sample lines for analysis
        $sample = $this->readSample($stream);
        fclose($stream);

        if (empty($sample)) {
            throw new \RuntimeException('Unable to read file content for format detection');
        }

        // Detect file type from extension and content
        $type = $this->detectTypeFromExtension($extension, $sample);

        // For XML, return early with minimal format info
        if ($type === FileType::Xml) {
            $encoding = $this->detectEncoding($sample);

            return new DetectedFormat(
                type: $type,
                delimiter: null,
                quoteChar: null,
                hasHeader: false,
                encoding: $encoding
            );
        }

        // Detect delimiter
        $delimiter = $this->detectDelimiter($sample);

        // Detect quote character
        $quoteChar = $this->detectQuoteChar($sample);

        // Detect header presence
        $hasHeader = $this->detectHeader($sample, $delimiter, $quoteChar);

        // Detect encoding
        $encoding = $this->detectEncoding($sample);

        return new DetectedFormat(
            type: $type,
            delimiter: $delimiter,
            quoteChar: $quoteChar,
            hasHeader: $hasHeader,
            encoding: $encoding
        );
    }

    /**
     * Detect file type from extension and content
     */
    private function detectTypeFromExtension(string $extension, array $sample): FileType
    {
        // Check content first (for XML detection even if extension is wrong)
        $firstLine = trim($sample[0] ?? '');
        if (str_starts_with($firstLine, '<?xml') || str_starts_with($firstLine, '<')) {
            return FileType::Xml;
        }

        // Then check extension
        return match ($extension) {
            'xls' => FileType::Xls,
            'xlsx' => FileType::Xlsx,
            'txt', 'tsv' => FileType::Txt,
            'json' => FileType::Json,
            'xml' => FileType::Xml,
            default => FileType::Csv, // Default to CSV for unknown extensions
        };
    }

    /**
     * Read sample lines from stream
     */
    private function readSample($stream): array
    {
        $lines = [];
        $lineCount = 0;

        while ($lineCount < self::SAMPLE_LINES && ! feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                break;
            }
            $lines[] = rtrim($line, "\r\n");
            $lineCount++;
        }

        return $lines;
    }

    /**
     * Detect delimiter by analyzing sample lines
     */
    private function detectDelimiter(array $sample): string
    {
        $delimiterCounts = [];

        foreach (self::DELIMITERS as $delimiter) {
            $count = 0;
            foreach ($sample as $line) {
                if (! empty($line)) {
                    $count += substr_count($line, $delimiter);
                }
            }
            $delimiterCounts[$delimiter] = $count;
        }

        // Find delimiter with highest count (and consistent across lines)
        $bestDelimiter = ',';
        $maxCount = 0;

        foreach ($delimiterCounts as $delimiter => $count) {
            if ($count > $maxCount && $this->isConsistentDelimiter($sample, $delimiter)) {
                $maxCount = $count;
                $bestDelimiter = $delimiter;
            }
        }

        return $bestDelimiter;
    }

    /**
     * Check if delimiter is consistent across sample lines
     */
    private function isConsistentDelimiter(array $sample, string $delimiter): bool
    {
        if (empty($sample)) {
            return false;
        }

        $firstLineCount = substr_count($sample[0], $delimiter);

        // Check if at least 50% of lines have similar delimiter count
        $similarCount = 0;
        foreach ($sample as $line) {
            $count = substr_count($line, $delimiter);
            if (abs($count - $firstLineCount) <= 1) {
                $similarCount++;
            }
        }

        return ($similarCount / count($sample)) >= 0.5;
    }

    /**
     * Detect quote character
     */
    private function detectQuoteChar(array $sample): string
    {
        $quoteCounts = [];

        foreach (self::QUOTE_CHARS as $quote) {
            $count = 0;
            foreach ($sample as $line) {
                $count += substr_count($line, $quote);
            }
            $quoteCounts[$quote] = $count;
        }

        // Return quote with highest count, default to double quote
        $bestQuote = '"';
        $maxCount = 0;

        foreach ($quoteCounts as $quote => $count) {
            if ($count > $maxCount) {
                $maxCount = $count;
                $bestQuote = $quote;
            }
        }

        return $bestQuote;
    }

    /**
     * Detect if file has header row
     */
    private function detectHeader(array $sample, string $delimiter, string $quoteChar): bool
    {
        if (count($sample) < 2) {
            return false;
        }

        $firstLine = $sample[0];
        $secondLine = $sample[1] ?? '';

        // Parse first two lines
        $firstFields = $this->parseLine($firstLine, $delimiter, $quoteChar);
        $secondFields = $this->parseLine($secondLine, $delimiter, $quoteChar);

        // If first line has different structure or looks like headers
        if (count($firstFields) !== count($secondFields)) {
            return false;
        }

        // Check if first line looks like headers (contains mostly non-numeric values)
        $numericCount = 0;
        foreach ($firstFields as $field) {
            $trimmed = trim($field, $quoteChar);
            if (is_numeric($trimmed)) {
                $numericCount++;
            }
        }

        // If less than 30% of first line fields are numeric, likely header
        return ($numericCount / max(count($firstFields), 1)) < 0.3;
    }

    /**
     * Parse a line with given delimiter and quote char
     */
    private function parseLine(string $line, string $delimiter, string $quoteChar): array
    {
        if (empty($line)) {
            return [];
        }

        // Simple CSV parsing (handles quoted fields)
        $fields = [];
        $currentField = '';
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === $quoteChar) {
                $inQuotes = ! $inQuotes;
            } elseif ($char === $delimiter && ! $inQuotes) {
                $fields[] = $currentField;
                $currentField = '';
            } else {
                $currentField .= $char;
            }
        }

        $fields[] = $currentField; // Add last field

        return $fields;
    }

    /**
     * Detect encoding
     */
    private function detectEncoding(array $sample): string
    {
        $content = implode("\n", $sample);

        // Try mb_detect_encoding first
        $detected = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);

        if ($detected !== false) {
            return $detected;
        }

        // Fallback to UTF-8
        return 'UTF-8';
    }
}
