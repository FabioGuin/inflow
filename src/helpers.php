<?php

use Illuminate\Support\Facades\Log;

if (! function_exists('inflow_report')) {
    /**
     * Report an exception to the logging system.
     *
     * Logs exceptions with appropriate context and log level based on severity.
     * Uses the dedicated 'inflow' logging channel for consistent log management.
     *
     * @param  Throwable  $exception  The exception to log
     * @param  string  $level  Log level: 'debug', 'info', 'warning', 'error' (default: 'warning')
     * @param  array<string, mixed>  $context  Additional context to include in the log
     * @param  string|null  $message  Custom message (default: uses exception message)
     */
    function inflow_report(
        Throwable $exception,
        string $level = 'warning',
        array $context = [],
        ?string $message = null
    ): void {
        $logMessage = $message ?? $exception->getMessage();
        $logContext = array_merge([
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ], $context);

        // Include stack trace for errors and warnings
        if (in_array($level, ['error', 'warning'], true)) {
            $logContext['trace'] = $exception->getTraceAsString();
        }

        $channel = function_exists('config')
            ? config('inflow.log_channel', 'inflow')
            : 'inflow';

        Log::channel($channel)->$level($logMessage, $logContext);
    }
}
