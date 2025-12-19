<?php

namespace InFlow\Services\DataProcessing;

use InFlow\Contracts\ReaderInterface;

/**
 * Service for reading preview data from readers.
 *
 * Handles the business logic of reading a limited number of rows from a reader
 * for preview purposes. Presentation logic (display, formatting) is handled by the caller.
 */
class DataPreviewService
{
    /**
     * Read preview rows from a reader.
     *
     * @param  ReaderInterface  $reader  The reader to read from
     * @param  int  $maxRows  Maximum number of rows to read
     * @return array{rows: array<int, array<string, mixed>>, count: int} Array of rows and count
     */
    public function readPreview(ReaderInterface $reader, int $maxRows): array
    {
        $rows = [];
        $rowCount = 0;

        foreach ($reader as $row) {
            $rows[] = $row;
            $rowCount++;
            if ($rowCount >= $maxRows) {
                break;
            }
        }

        return [
            'rows' => $rows,
            'count' => $rowCount,
        ];
    }
}
