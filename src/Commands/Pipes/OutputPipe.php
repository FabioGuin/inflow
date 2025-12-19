<?php

namespace InFlow\Commands\Pipes;

use Closure;
use InFlow\Commands\InFlowCommandContext;
use InFlow\Contracts\ProcessingPipeInterface;
use InFlow\ValueObjects\ProcessingContext;

/**
 * Final step of the ETL pipeline: completion message.
 */
readonly class OutputPipe implements ProcessingPipeInterface
{
    public function __construct(
        private InFlowCommandContext $command
    ) {}

    /**
     * Display completion message.
     *
     * @param  ProcessingContext  $context  The processing context
     * @param  Closure  $next  The next pipe in the pipeline
     * @return ProcessingContext Updated context
     */
    public function handle(ProcessingContext $context, Closure $next): ProcessingContext
    {
        if (! $context->cancelled && $context->flowRun !== null) {
            $this->command->success('Flow execution completed');
        }

        return $next($context);
    }
}
