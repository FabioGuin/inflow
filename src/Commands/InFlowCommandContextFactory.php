<?php

namespace InFlow\Commands;

use Illuminate\Contracts\Container\Container;

readonly class InFlowCommandContextFactory
{
    public function __construct(
        private Container $container
    ) {}

    public function make(InFlowCommand $command): InFlowCommandContext
    {
        return new InFlowCommandContext($command, $this->container);
    }
}


