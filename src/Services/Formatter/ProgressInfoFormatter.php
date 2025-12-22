<?php

namespace InFlow\Services\Formatter;

use InFlow\ViewModels\ProgressInfoViewModel;

/**
 * Formatter for progress information with metrics
 */
readonly class ProgressInfoFormatter
{
    public function format(
        string $message,
        ?int $lines = null,
        ?int $bytes = null,
        ?int $rows = null,
        ?int $columns = null
    ): ProgressInfoViewModel {
        return new ProgressInfoViewModel(
            message: $message,
            lines: $lines,
            bytes: $bytes,
            rows: $rows,
            columns: $columns,
        );
    }
}
