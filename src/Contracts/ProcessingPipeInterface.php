<?php

namespace InFlow\Contracts;

use Closure;
use InFlow\ValueObjects\ProcessingContext;

/**
 * Interface for processing pipeline pipes
 */
interface ProcessingPipeInterface
{
    /**
     * Process the context and pass it to the next pipe
     */
    public function handle(ProcessingContext $context, Closure $next): ProcessingContext;
}
