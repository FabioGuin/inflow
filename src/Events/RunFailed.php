<?php

namespace InFlow\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a FlowRun fails
 */
class RunFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sourceFile,
        public string $message,
        public ?\Throwable $exception = null,
        public array $context = []
    ) {}
}
