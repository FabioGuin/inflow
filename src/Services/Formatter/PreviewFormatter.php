<?php

namespace InFlow\Services\Formatter;

use InFlow\Contracts\ReaderInterface;
use InFlow\ViewModels\PreviewViewModel;

/**
 * Formatter for data preview
 */
readonly class PreviewFormatter
{
    public function format(ReaderInterface $reader, array $rows, int $previewRows): PreviewViewModel
    {
        $headers = $reader->getHeaders();
        $title = "Preview (first {$previewRows} rows)";

        if (! empty($headers)) {
            $tableData = [];
            foreach ($rows as $row) {
                $tableData[] = array_values($row);
            }

            return new PreviewViewModel(
                title: $title,
                headers: $headers,
                tableData: $tableData,
            );
        }

        return new PreviewViewModel(
            title: $title,
            headers: null,
            tableData: [],
            rawRows: $rows,
        );
    }
}
