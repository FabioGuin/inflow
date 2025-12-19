<?php

namespace InFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use InFlow\ValueObjects\QualityReport;
use InFlow\ValueObjects\SourceSchema;

/**
 * Event fired when data profiling is completed
 */
class ProfileCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sourceFile,
        public SourceSchema $schema,
        public QualityReport $qualityReport
    ) {}
}
