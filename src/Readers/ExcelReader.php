<?php

namespace InFlow\Readers;

use InFlow\Contracts\ReaderInterface;
use InFlow\Enums\File\FileType;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\File\DetectedFormat;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel reader (XLS/XLSX) with support for large files
 */
class ExcelReader implements ReaderInterface
{
    private Spreadsheet $spreadsheet;

    private ?array $headers = null;

    private ?array $currentRow = null;

    private int $currentLineNumber = 0;

    private int $key = -1;

    private int $highestRow = 0;

    private string $highestColumn = 'A';

    private int $startRow = 1;

    private bool $initialized = false;

    public function __construct(
        private readonly FileSource $source,
        private readonly DetectedFormat $format
    ) {}

    /**
     * Initialize reader and load spreadsheet
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            $reader = IOFactory::createReader($this->getReaderType());
            $reader->setReadDataOnly(true); // Read only data, not formatting
            $reader->setReadEmptyCells(false); // Skip empty cells

            $this->spreadsheet = $reader->load($this->source->getPath());
            $worksheet = $this->spreadsheet->getActiveSheet();

            $this->highestRow = $worksheet->getHighestRow();
            $this->highestColumn = $worksheet->getHighestColumn();

            // For Excel files, try to detect header by checking if first row looks like headers
            // (contains mostly non-numeric values)
            $hasHeader = $this->format->hasHeader;
            if (! $hasHeader && $this->highestRow > 1) {
                // Auto-detect: if first row has mostly non-numeric values, treat as header
                $firstRow = $this->readRow($worksheet, 1);
                $secondRow = $this->readRow($worksheet, 2);
                $numericCount = 0;
                foreach ($firstRow as $value) {
                    $trimmed = trim($value);
                    if (is_numeric($trimmed)) {
                        $numericCount++;
                    }
                }
                // If less than 30% of first row fields are numeric, likely header
                $hasHeader = (count($firstRow) > 0 && ($numericCount / max(count($firstRow), 1)) < 0.3);
            }

            // Load headers if present
            if ($hasHeader && $this->highestRow > 0) {
                $this->headers = $this->readRow($worksheet, 1);
                $this->startRow = 2; // Start from row 2 if header exists
            } else {
                $this->startRow = 1; // Start from row 1 if no header
            }

            $this->initialized = true;
        } catch (ReaderException $e) {
            \inflow_report($e, 'error', ['operation' => 'readExcelFile', 'file' => $source->getPath()]);
            throw new \RuntimeException('Failed to read Excel file: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get reader type based on file extension
     */
    private function getReaderType(): string
    {
        return match ($this->format->type) {
            FileType::Xlsx => 'Xlsx',
            FileType::Xls => 'Xls',
            default => 'Xlsx', // Default to XLSX
        };
    }

    /**
     * Read a row from worksheet
     */
    private function readRow(Worksheet $worksheet, int $rowIndex): array
    {
        $row = [];
        $columnIndex = 'A';

        // Read up to highest column
        while ($columnIndex <= $this->highestColumn) {
            $cell = $worksheet->getCell($columnIndex.$rowIndex);
            $value = $cell->getValue();

            // Convert to string, handling null values
            $row[] = $value !== null ? (string) $value : '';

            // Increment column (A -> B -> C, etc.)
            $columnIndex++;
        }

        return $row;
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

        $this->key++;
        $rowIndex = $this->startRow + $this->key;

        if ($rowIndex > $this->highestRow) {
            $this->currentRow = null;

            return;
        }

        $worksheet = $this->spreadsheet->getActiveSheet();
        $this->currentLineNumber = $rowIndex;
        $parsed = $this->readRow($worksheet, $rowIndex);

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
        $this->currentRow = null;
        $this->currentLineNumber = 0;
        $this->key = -1;
        $this->headers = null;
        $this->initialized = false;
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
     * Get total number of rows
     */
    public function getTotalRows(): int
    {
        $this->initialize();

        return $this->highestRow - ($this->format->hasHeader ? 1 : 0);
    }
}
