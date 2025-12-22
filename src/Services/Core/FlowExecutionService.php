<?php

namespace InFlow\Services\Core;

use InFlow\Contracts\ReaderInterface;
use InFlow\Detectors\FormatDetector;
use InFlow\Readers\CsvReader;
use InFlow\Readers\ExcelReader;
use InFlow\Readers\JsonLinesReader;
use InFlow\Readers\XmlReader;
use InFlow\Services\DataProcessing\SanitizationService;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\File\DetectedFormat;

/**
 * Service for flow execution business logic.
 *
 * Handles pure business logic operations for flow execution:
 * - File sanitization and temporary file management
 * - Format detection
 * - Reader creation
 * - Row counting
 *
 * Presentation logic (events, logging) is handled by the caller.
 */
readonly class FlowExecutionService
{
    public function __construct(
        private SanitizationService $sanitizationService,
        private FormatDetector $formatDetector
    ) {}

    /**
     * Prepare source file (load and sanitize if needed).
     *
     * Business logic: reads file, sanitizes if config provided, creates temp file if needed.
     *
     * @param  string  $sourceFile  The source file path
     * @param  array<string, mixed>  $sanitizerConfig  Sanitizer configuration (empty if disabled)
     * @return array{0: FileSource, 1: string|null, 2: \InFlow\Contracts\SanitizationReportInterface|null} Tuple of [source, tempFile, report] (tempFile and report are null if no sanitization)
     *
     * @throws \RuntimeException If file reading fails
     */
    public function prepareSourceFile(string $sourceFile, array $sanitizerConfig): array
    {
        $source = FileSource::fromPath($sourceFile);
        $tempFile = null;
        $report = null;

        // Sanitize if enabled
        if (! empty($sanitizerConfig)) {
            $content = file_get_contents($sourceFile);
            if ($content === false) {
                throw new \RuntimeException('Failed to read source file: '.$sourceFile);
            }

            // Business logic: sanitize content
            [$sanitized, $report] = $this->sanitizationService->sanitize($content, $sanitizerConfig);

            // Business logic: save sanitized content to temporary file
            $tempFile = sys_get_temp_dir().'/inflow_'.uniqid().'_'.basename($sourceFile);
            file_put_contents($tempFile, $sanitized);
            $source = FileSource::fromPath($tempFile);
        }

        return [$source, $tempFile, $report];
    }

    /**
     * Detect file format.
     *
     * Business logic: detects format from source.
     *
     * @param  FileSource  $source  The file source
     * @param  array<string, mixed>|null  $formatConfig  Optional format configuration (not yet used)
     * @return DetectedFormat The detected format
     */
    public function detectFormat(FileSource $source, ?array $formatConfig): DetectedFormat
    {
        // Use format config if provided, otherwise auto-detect
        if ($formatConfig !== null) {
            // TODO: Support manual format configuration
            // For now, we still auto-detect but could use formatConfig for overrides
        }

        return $this->formatDetector->detect($source);
    }

    /**
     * Create appropriate reader based on format.
     *
     * Business logic: creates reader instance based on format type.
     *
     * @param  FileSource  $source  The file source
     * @param  DetectedFormat  $format  The detected format
     * @return JsonLinesReader|CsvReader|ExcelReader|XmlReader|null The reader instance or null if unsupported
     */
    public function createReader(FileSource $source, DetectedFormat $format): JsonLinesReader|CsvReader|ExcelReader|XmlReader|null
    {
        if ($format->type->isCsv()) {
            return new CsvReader($source, $format);
        } elseif ($format->type->isExcel()) {
            return new ExcelReader($source, $format);
        } elseif ($format->type->isJson()) {
            return new JsonLinesReader($source);
        } elseif ($format->type->isXml()) {
            return new XmlReader($source);
        }

        return null;
    }

    /**
     * Count total rows in reader.
     *
     * Business logic: counts rows by iterating through reader.
     *
     * @param  ReaderInterface  $reader  The reader
     * @return int The total number of rows
     */
    public function countRows(ReaderInterface $reader): int
    {
        $count = 0;
        $reader->rewind();

        foreach ($reader as $row) {
            $count++;
        }

        $reader->rewind();

        return $count;
    }
}
