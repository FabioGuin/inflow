<?php

namespace InFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use InFlow\ValueObjects\DetectedFormat;

/**
 * Event fired when file format is detected
 */
class FormatDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sourceFile,
        public DetectedFormat $format
    ) {}
}
