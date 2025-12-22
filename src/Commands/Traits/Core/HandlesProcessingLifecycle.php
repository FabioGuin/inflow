<?php

namespace InFlow\Commands\Traits\Core;

use Illuminate\Console\Command;
use InFlow\Presenters\Contracts\PresenterInterface;
use InFlow\Services\Formatter\FlowRunFormatter;
use InFlow\Services\Formatter\SummaryFormatter;
use InFlow\ValueObjects\Flow\ProcessingContext;

trait HandlesProcessingLifecycle
{
    /**
     * Create the initial processing context for the ETL pipeline.
     *
     * Initializes a new ProcessingContext with the file path and start time for duration
     * calculation. This context is passed through the entire processing pipeline.
     *
     * @param  string  $filePath  The source file path to process
     * @param  float  $startTime  The start time in microseconds (from microtime(true))
     * @return ProcessingContext The initialized processing context
     */
    private function createProcessingContext(string $filePath, float $startTime): ProcessingContext
    {
        return new ProcessingContext(
            filePath: $filePath,
            startTime: $startTime
        );
    }

    /**
     * Display the processing summary based on the execution context.
     *
     * Calculates the total processing duration and displays the appropriate summary:
     * - If a FlowRun exists: displays detailed flow execution results (imported rows,
     *   skipped rows, errors, success rate, etc.)
     * - Otherwise: displays basic processing summary (lines processed, bytes processed,
     *   processing time, throughput)
     *
     * The summary is automatically skipped in quiet mode by the display methods.
     *
     * @param  ProcessingContext  $context  The processing context after pipeline execution
     * @param  float  $startTime  The start time in microseconds (from microtime(true))
     * @param  PresenterInterface  $presenter  The presenter for output
     * @param  FlowRunFormatter  $flowRunFormatter  Formatter for flow run
     * @param  SummaryFormatter  $summaryFormatter  Formatter for summary
     */
    private function displayProcessingSummary(
        ProcessingContext $context,
        float $startTime,
        PresenterInterface $presenter,
        FlowRunFormatter $flowRunFormatter,
        SummaryFormatter $summaryFormatter
    ): void {
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 3);

        if ($context->flowRun !== null) {
            $viewModel = $flowRunFormatter->format($context->flowRun);
            $presenter->presentFlowRun($viewModel);
        } else {
            $lineCount = $context->lineCount ?? 0;
            $contentLength = $context->content !== null ? strlen($context->content) : 0;
            $viewModel = $summaryFormatter->format($lineCount, $contentLength, $duration);
            $presenter->presentSummary($viewModel);
        }
    }

    /**
     * Determine the appropriate exit code based on the processing context.
     *
     * Evaluates the processing result and returns the appropriate command exit code:
     * - Returns FAILURE if processing was cancelled by the user
     * - Returns FAILURE if a flow run was executed and its status is 'failed'
     * - Returns SUCCESS in all other cases (completed, partially completed, or no flow run)
     *
     * This method centralizes all exit code logic to ensure consistent behavior.
     *
     * @param  ProcessingContext  $context  The processing context after pipeline execution
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function getExitCode(ProcessingContext $context): int
    {
        if ($context->cancelled) {
            return Command::FAILURE;
        }

        if ($context->flowRun !== null && $context->flowRun->status->value === 'failed') {
            return Command::FAILURE;
        }

        // Treat any collected row-level errors as a failing run for the CLI process.
        // These errors are intentionally caught inside FlowExecutor to allow "continue" policies,
        // but the command should still surface them via a non-zero exit code.
        if ($context->flowRun !== null && $context->flowRun->errorCount > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
