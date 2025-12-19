<?php

namespace InFlow\Commands\Traits\Core;

trait HandlesFirstTimeSetup
{
    /**
     * Run the guided setup wizard for first-time users if needed.
     *
     * Detects if this is a first-time setup (no command options provided) and launches
     * an interactive configuration wizard to help users configure their import step by step.
     * The wizard collects configuration for sanitization, output path, and other options.
     *
     * The wizard is automatically skipped in:
     * - Quiet mode (--quiet flag)
     * - Non-interactive mode (--no-interaction flag)
     * - When command options are already provided
     */
    private function runFirstTimeSetupIfNeeded(): void
    {
        if (! $this->isQuiet() && $this->isFirstTimeSetup() && ! $this->option('no-interaction')) {
            $this->note('First-time setup detected. Let\'s configure your import step by step...');
            $this->guidedConfig = $this->guidedSetup();
            $this->newLine();
        }
    }

    /**
     * Check if this is a first-time setup by detecting if any significant options are provided.
     *
     * A first-time setup is detected when none of the following options are specified:
     * - --sanitize
     * - --output
     * - --mapping
     * - --newline-format
     *
     * When no options are provided, the user is likely running the command for the first time
     * and would benefit from the guided setup wizard.
     *
     * @return bool True if this appears to be a first-time setup, false otherwise
     */
    private function isFirstTimeSetup(): bool
    {
        return $this->services->configResolver->isFirstTimeSetup(
            fn (string $key) => $this->option($key)
        );
    }
}
