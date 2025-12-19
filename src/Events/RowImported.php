<?php

namespace InFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a row is successfully imported
 */
class RowImported
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $rowNumber,
        public array $rowData,
        public mixed $model = null
    ) {}
}
