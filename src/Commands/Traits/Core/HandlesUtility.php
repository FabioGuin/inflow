<?php

namespace InFlow\Commands\Traits\Core;

use InFlow\Constants\DisplayConstants;

/**
 * Trait for utility methods (configuration, summary display).
 *
 * Provides helper methods for configuration resolution and summary display.
 */
trait HandlesUtility
{
    /**
     * Get option value (from command option, guided config, or config file)
     */
    public function getOption(string $key): mixed
    {
        return $this->configResolver->resolveOption(
            $key,
            $this->guidedConfig,
            fn (string $optionKey) => $this->option($optionKey)
        );
    }

    /**
     * Display processing summary
     */
    private function displaySummary(int $lineCount, int $byteCount, float $duration): void
    {
        if ($this->isQuiet()) {
            return;
        }

        $this->line('<fg=cyan>Processing Summary</>');
        $this->line(DisplayConstants::SECTION_SEPARATOR);

        // Business logic: format summary data
        $formatted = $this->summaryFormatter->formatForTable($lineCount, $byteCount, $duration);

        // Presentation: display table
        $this->table($formatted['headers'], $formatted['table_data']);
        $this->newLine();

        $this->line('<fg=green>✨ Processing completed successfully!</>');
        $this->newLine();
    }
}
