<?php

namespace InFlow\Commands\Traits\Core;

use Illuminate\Console\Command;
use InFlow\Constants\DisplayConstants;
use InFlow\ValueObjects\Flow\FlowRun;
use InFlow\ValueObjects\Flow\ProcessingContext;

trait HandlesProcessingLifecycle
{
    /**
     * Create the initial processing context for the ETL pipeline.
     *
     * Initializes a new ProcessingContext with the file path, start time for duration
     * calculation, and any guided configuration from the setup wizard. This context
     * is passed through the entire processing pipeline.
     *
     * @param  string  $filePath  The source file path to process
     * @param  float  $startTime  The start time in microseconds (from microtime(true))
     * @return ProcessingContext The initialized processing context
     */
    private function createProcessingContext(string $filePath, float $startTime): ProcessingContext
    {
        return new ProcessingContext(
            filePath: $filePath,
            startTime: $startTime,
            guidedConfig: $this->guidedConfig
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
     */
    private function displayProcessingSummary(ProcessingContext $context, float $startTime): void
    {
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 3);

        if ($context->flowRun !== null) {
            $this->displayFlowRunSummary($context->flowRun, $duration);
        } else {
            $lineCount = $context->lineCount ?? 0;
            $contentLength = $context->content !== null ? strlen($context->content) : 0;
            $this->displaySummary($lineCount, $contentLength, $duration);
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

    /**
     * Display flow run summary.
     *
     * Displays a concise summary of the flow execution results including status,
     * statistics, duration, and success rate. This is a shorter version compared
     * to the detailed results displayed by ExecuteFlowPipe.
     *
     * @param  FlowRun  $run  The flow run results
     * @param  float  $duration  The total processing duration in seconds
     */
    private function displayFlowRunSummary(FlowRun $run, float $duration): void
    {
        if ($this->isQuiet()) {
            return;
        }

        $this->newLine();
        $this->line('<fg=cyan>Processing Summary</>');
        $this->line(DisplayConstants::SECTION_SEPARATOR);

        // Simplified formatting without formatter service
        $statusIcon = match ($run->status->value) {
            'completed' => '<fg=green>✓</>',
            'partially_completed' => '<fg=yellow>⚠</>',
            'failed' => '<fg=red>✗</>',
            default => '○',
        };

        $this->line("{$statusIcon} Status: <fg=white>{$run->status->value}</>");
        $this->line("  Imported: <fg=yellow>".number_format($run->importedRows)."</>");
        $this->line("  Skipped: <fg=yellow>".number_format($run->skippedRows)."</>");
        $this->line("  Errors: <fg=yellow>".number_format($run->errorCount)."</>");
        $this->line("  Duration: <fg=yellow>".round($duration, 2)."s</>");

        if ($run->totalRows > 0) {
            $successRate = round(($run->importedRows / $run->totalRows) * 100, 1);
            $this->line("  Success rate: <fg=yellow>{$successRate}%</>");
        }

        if ($run->errorCount > 0) {
            $this->line("  <fg=red>Errors encountered: {$run->errorCount}</>");
        }

        $this->newLine();
        $completionLine = match ($run->status->value) {
            'completed' => '<fg=green>Processing completed successfully.</>',
            'partially_completed' => '<fg=yellow>Processing completed with errors.</>',
            'failed' => '<fg=red>Processing failed.</>',
            default => '<fg=cyan>Processing finished.</>',
        };

        $this->line($completionLine);
        $this->newLine();
    }
}
