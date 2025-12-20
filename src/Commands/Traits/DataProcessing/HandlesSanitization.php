<?php

namespace InFlow\Commands\Traits\DataProcessing;

/**
 * Trait for handling sanitizer configuration.
 *
 * Provides method to get sanitizer configuration from config file and command options.
 */
trait HandlesSanitization
{
    /**
     * Get sanitizer configuration.
     *
     * Builds configuration from config file and command options.
     * Used by both SanitizePipe and ExecuteFlowPipe.
     *
     * @return array<string, mixed> The sanitizer configuration array
     */
    public function getSanitizerConfig(): array
    {
        return $this->configResolver->buildSanitizerConfig(
            fn (string $key) => $this->getOption($key)
        );
    }
}
