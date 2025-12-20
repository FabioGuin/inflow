<?php

namespace InFlow\Commands\Traits\Core;

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
}
