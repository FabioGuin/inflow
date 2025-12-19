<?php

namespace InFlow\Commands\Traits\Core;

trait HandlesExceptions
{
    /**
     * Handle runtime exceptions that occur during file processing.
     *
     * Runtime exceptions typically indicate expected errors such as:
     * - File not found
     * - Invalid file format
     * - Permission errors
     * - Other recoverable processing errors
     *
     * Displays a user-friendly error message and returns FAILURE exit code.
     *
     * @param  \RuntimeException  $e  The runtime exception that occurred
     * @return int Always returns Command::FAILURE
     */
    private function handleRuntimeException(\RuntimeException $e): int
    {
        \inflow_report($e, 'error', ['operation' => 'handleRuntimeException']);
        $this->newLine();
        $this->error('Error processing file');
        $this->line("  <fg=red>{$e->getMessage()}</>");
        $this->newLine();

        return \Illuminate\Console\Command::FAILURE;
    }

    /**
     * Handle unexpected exceptions that occur during processing.
     *
     * Catches all other exceptions that are not runtime exceptions, typically indicating
     * unexpected errors such as:
     * - Internal errors
     * - Unexpected state errors
     * - Bugs or unhandled edge cases
     *
     * Displays a user-friendly error message with optional verbose information
     * (file and line number) when verbose mode is enabled.
     *
     * @param  \Exception  $e  The unexpected exception that occurred
     * @return int Always returns Command::FAILURE
     */
    private function handleUnexpectedException(\Exception $e): int
    {
        \inflow_report($e, 'error', ['operation' => 'handleUnexpectedException']);
        $this->newLine();
        $this->error('Unexpected error');
        $this->line("  <fg=red>{$e->getMessage()}</>");
        if ($this->getOutput()->isVerbose()) {
            $this->line("  <fg=gray>File: {$e->getFile()}:{$e->getLine()}</>");
        }
        $this->newLine();

        return \Illuminate\Console\Command::FAILURE;
    }
}
