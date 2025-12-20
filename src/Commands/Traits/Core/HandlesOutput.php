<?php

namespace InFlow\Commands\Traits\Core;

trait HandlesOutput
{
    /**
     * Check if quiet mode is enabled.
     *
     * Quiet mode suppresses prompts, delays, and most output messages.
     * Uses Laravel's built-in --quiet option from the command line.
     *
     * @return bool True if quiet mode is active, false otherwise
     */
    public function isQuiet(): bool
    {
        return $this->option('quiet') === true;
    }

    /**
     * Display an informational line to the console.
     *
     * The message is automatically skipped in quiet mode unless explicitly forced.
     * Supports ANSI color codes for formatted output.
     *
     * @param  string  $message  The message to display (supports ANSI color codes)
     * @param  bool  $force  If true, display the message even in quiet mode
     */
    public function infoLine(string $message, bool $force = false): void
    {
        if (! $this->isQuiet() || $force) {
            $this->line($message);
            $this->flushOutput();
        }
    }

    /**
     * Display a formatted note using Laravel Prompts.
     *
     * Wrapper around Laravel Prompts note() function that respects quiet mode.
     * Automatically skipped when quiet mode is enabled.
     *
     * @param  string  $string  The note message to display
     * @param  string  $type  The note type: 'info', 'warning', 'error' (default: 'info')
     */
    public function note(string $string, string $type = 'info'): void
    {
        if (! $this->isQuiet()) {
            \Laravel\Prompts\note($string, $type);
        }
    }

    /**
     * Display an error message with error icon and red text.
     *
     * Uses Laravel Prompts error() function to display formatted error messages.
     * Automatically skipped when quiet mode is enabled.
     * Compatible with Illuminate\Console\Command::error() signature.
     *
     * @param  string  $string  The error message to display
     * @param  int|null  $verbosity  Output verbosity level (for compatibility with parent class)
     */
    public function error($string, $verbosity = null): void
    {
        if (! $this->isQuiet()) {
            \Laravel\Prompts\error($string);
        }
    }

    /**
     * Display a warning message with warning icon and yellow/orange text.
     *
     * Uses Laravel Prompts warning() function to display formatted warning messages.
     * Automatically skipped when quiet mode is enabled.
     *
     * @param  string  $string  The warning message to display
     */
    public function warning(string $string): void
    {
        if (! $this->isQuiet()) {
            \Laravel\Prompts\warning($string);
        }
    }

    /**
     * Display a success message with checkmark icon and green text.
     *
     * Uses Laravel Prompts info() function internally to display success messages
     * with appropriate styling. Automatically skipped when quiet mode is enabled.
     *
     * @param  string  $message  The success message to display
     */
    public function success(string $message): void
    {
        if (! $this->isQuiet()) {
            \Laravel\Prompts\info($message);
        }
    }

    /**
     * Output a blank line to the console.
     *
     * Automatically skipped when quiet mode is enabled.
     */
    public function newLine($count = 1): void
    {
        if (! $this->isQuiet()) {
            $this->output->newLine($count);
        }
    }

    /**
     * Flush output buffers to ensure real-time display.
     *
     * Flushes both PHP output buffers and system buffers to ensure that
     * console output is displayed immediately, which is important for
     * progress indicators and real-time feedback.
     */
    public function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Check if a parameter option was passed in the command line.
     *
     * Wrapper around the protected input property to allow pipes to check
     * if an option was explicitly passed.
     *
     * @param  string  $name  The option name (with or without -- prefix)
     * @param  bool  $onlyParams  If true, only check parameters, not options
     * @return bool True if the option was passed
     */
    public function hasParameterOption(string $name, bool $onlyParams = false): bool
    {
        return $this->input->hasParameterOption($name, $onlyParams);
    }

    /**
     * Delay between steps in microseconds (1 second = 1000000 microseconds).
     */
    /**
     * Prompt user to select from a list of options with automatic fallback to text input.
     *
     * Attempts to use select() prompt first. If that fails (e.g., non-interactive mode),
     * automatically falls back to text() prompt. This eliminates the need for manual
     * try-catch blocks around prompts.
     *
     * @param  string  $label  The prompt label
     * @param  array<string, string>  $options  Associative array of [value => label]
     * @param  int  $scroll  Number of items to show before scrolling
     * @return string|null Selected value, or null if cancelled/required validation fails
     */
    public function selectWithFallback(string $label, array $options, int $scroll = 15): ?string
    {
        if ($this->isQuiet() || $this->option('no-interaction')) {
            return null;
        }

        try {
            return \Laravel\Prompts\select(
                label: $label,
                options: $options,
                scroll: $scroll
            );
        } catch (\Laravel\Prompts\Exceptions\NonInteractiveValidationException $e) {
            \inflow_report($e, 'debug', ['operation' => 'selectWithFallback', 'label' => $label]);

            return $this->textWithValidation(
                label: $label,
                validate: fn ($value) => isset($options[$value]) ? null : 'Invalid selection.'
            );
        }
    }

    /**
     * Prompt user for text input with validation and automatic error handling.
     *
     * Wrapper around Laravel Prompts text() that handles NonInteractiveValidationException
     * gracefully by returning null instead of throwing.
     *
     * @param  string  $label  The prompt label
     * @param  string  $placeholder  Optional placeholder text
     * @param  bool  $required  Whether the input is required
     * @param  callable|null  $validate  Validation function: fn($value) => string|null (error message)
     * @return string|null Entered value, or null if cancelled/validation fails in non-interactive mode
     */
    public function textWithValidation(
        string $label,
        string $placeholder = '',
        bool $required = true,
        ?callable $validate = null
    ): ?string {
        if ($this->isQuiet() || $this->option('no-interaction')) {
            return null;
        }

        try {
            return \Laravel\Prompts\text(
                label: $label,
                placeholder: $placeholder,
                required: $required,
                validate: $validate
            );
        } catch (\Laravel\Prompts\Exceptions\NonInteractiveValidationException $e) {
            \inflow_report($e, 'debug', ['operation' => 'textWithValidation', 'label' => $label]);

            return null;
        }
    }

    /**
     * Ask for input with cancel option.
     *
     * Returns null if user types "cancel" (case-insensitive), otherwise returns the input value.
     *
     * @param  string  $label  The prompt label
     * @param  string  $placeholder  Optional placeholder text
     * @return string|null Entered value, or null if cancelled
     */
    public function askWithCancel(string $label, string $placeholder = ''): ?string
    {
        $value = $this->ask($label, $placeholder);

        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return $normalized === 'cancel' ? null : $value;
    }

    /**
     * Prompt for boolean confirmation with back option.
     *
     * Uses ask() instead of confirm() to allow the user to type "back" to go back.
     * Returns null if user types "back", otherwise returns a boolean value.
     *
     * @param  string  $label  The prompt label
     * @param  bool  $default  Default value if user just presses Enter
     * @return bool|null Boolean value, or null if user wants to go back
     */
    public function confirmWithBack(string $label, bool $default = true): ?bool
    {
        if ($this->isQuiet() || $this->option('no-interaction')) {
            return $default;
        }

        $value = $this->ask($label, $default ? 'y' : 'n');

        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim($value));

        // Check for back command
        if (\InFlow\Enums\UI\InteractiveCommand::isBack($normalized)) {
            return null;
        }

        // Parse boolean value
        return in_array($normalized, ['y', 'yes', '1', 'true', 'on', 'enabled'], true);
    }
}
