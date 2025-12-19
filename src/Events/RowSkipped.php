<?php

namespace InFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a row is skipped (validation error, duplicate, etc.)
 */
class RowSkipped
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $rowNumber,
        public array $rowData,
        public string $reason,
        public array $errors = []
    ) {}
}
