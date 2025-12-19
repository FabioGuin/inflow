<?php

namespace InFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use InFlow\Sanitizers\SanitizationReport;

/**
 * Event fired when file sanitization is completed
 */
class SanitizationCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sourceFile,
        public SanitizationReport $report
    ) {}
}
