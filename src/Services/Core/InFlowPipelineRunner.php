<?php

namespace InFlow\Services\Core;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Pipeline;
use InFlow\Commands\InFlowCommand;
use InFlow\Commands\InFlowCommandContextFactory;
use InFlow\Commands\Pipes\DetectFormatPipe;
use InFlow\Commands\Pipes\ExecuteFlowPipe;
use InFlow\Commands\Pipes\LoadFilePipe;
use InFlow\Commands\Pipes\OutputPipe;
use InFlow\Commands\Pipes\PreExecutionReviewPipe;
use InFlow\Commands\Pipes\ProcessMappingPipe;
use InFlow\Commands\Pipes\ProfileDataPipe;
use InFlow\Commands\Pipes\ReadContentPipe;
use InFlow\Commands\Pipes\ReadDataPipe;
use InFlow\Commands\Pipes\SanitizePipe;
use InFlow\ValueObjects\ProcessingContext;

readonly class InFlowPipelineRunner
{
    public function __construct(
        private Container $container,
        private InFlowCommandContextFactory $commandContextFactory
    ) {}

    public function run(InFlowCommand $command, ProcessingContext $context): ProcessingContext
    {
        $commandContext = $this->commandContextFactory->make($command);

        $pipeClasses = [
            LoadFilePipe::class,
            ReadContentPipe::class,
            SanitizePipe::class,
            DetectFormatPipe::class,
            ReadDataPipe::class,
            ProfileDataPipe::class,
            ProcessMappingPipe::class,
            PreExecutionReviewPipe::class,
            ExecuteFlowPipe::class,
            OutputPipe::class,
        ];

        return Pipeline::send($context)
            ->through(array_map(
                fn (string $pipeClass) => $this->container->makeWith($pipeClass, ['command' => $commandContext]),
                $pipeClasses
            ))
            ->then(fn (ProcessingContext $context) => $context);
    }
}
