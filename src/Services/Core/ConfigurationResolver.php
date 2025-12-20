<?php

namespace InFlow\Services\Core;

use InFlow\Enums\Config\ConfigKey;
use InFlow\Enums\File\NewlineFormat;
use InFlow\Sanitizers\SanitizerConfigKeys;

class ConfigurationResolver
{
    /**
     * Resolve option value from multiple sources in priority order.
     *
     * Priority order:
     * 1. Guided config (from interactive wizard)
     * 2. Command option (explicit override)
     * 3. Config file default
     *
     * @param  string  $key  The option key to resolve
     * @param  array<string, mixed>  $guidedConfig  Configuration from guided setup wizard
     * @param  callable  $getCommandOption  Callback to get command option value
     * @return mixed The resolved option value or null if not found
     */
    public function resolveOption(string $key, array $guidedConfig, callable $getCommandOption): mixed
    {
        // First check guided config (from wizard)
        if (isset($guidedConfig[$key])) {
            return $guidedConfig[$key];
        }

        // Then check command option (explicit override)
        $optionValue = $getCommandOption($key);
        if ($optionValue !== null) {
            return $optionValue;
        }

        // Finally, fall back to config file default
        return $this->getConfigDefault($key);
    }

    /**
     * Get default value from config file.
     *
     * Attempts to load the value from Laravel config first, then falls back
     * to the package default configuration file.
     *
     * @param  string  $key  The option key (must be a valid ConfigKey enum value)
     * @return mixed The default value or null if not found
     */
    public function getConfigDefault(string $key): mixed
    {
        $configKeyEnum = ConfigKey::tryFrom($key);
        if ($configKeyEnum === null) {
            return null;
        }

        $configKey = $configKeyEnum->toConfigKey();

        // Try to load from Laravel config first
        if (function_exists('config')) {
            $value = config("inflow.command.{$configKey}");
            if ($value !== null) {
                return $value;
            }
        }

        // If not available, use package default
        $configPath = dirname(__DIR__, 3).'/config/inflow.php';
        if (file_exists($configPath)) {
            $packageConfig = require $configPath;
            $value = $packageConfig['command'][$configKey] ?? null;
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Get default sanitizer configuration from config file.
     *
     * Retrieves default values from Laravel config file (config('inflow.sanitizer'))
     * or package default config file. Returns normalized config with enum keys.
     * The package config file always exists and contains default values.
     *
     * @return array<string, mixed> The sanitizer configuration array with normalized keys
     */
    public function getDefaultSanitizerConfig(): array
    {
        $config = [];

        // Try to load from Laravel config first
        if (function_exists('config')) {
            $config = config('inflow.sanitizer', []);
        }

        // If not available, use package default config file (always exists)
        if (empty($config)) {
            $configPath = dirname(__DIR__, 3).'/config/inflow.php';
            $packageConfig = require $configPath;
            $config = $packageConfig['sanitizer'] ?? [];
        }

        // Normalize and return config
        return $this->normalizeSanitizerConfig($config);
    }

    /**
     * Build sanitizer configuration from multiple sources.
     *
     * Merges configuration from:
     * 1. Laravel config file (config('inflow.sanitizer'))
     * 2. Package default config file
     * 3. Command option overrides (newline-format)
     *
     * @param  callable  $getOption  Callback to get option value (for newline-format override)
     * @return array<string, mixed> The sanitizer configuration array
     */
    public function buildSanitizerConfig(callable $getOption): array
    {
        $config = $this->getDefaultSanitizerConfig();

        // Override with command options (newline-format)
        $newlineFormatOption = $getOption(ConfigKey::NewlineFormat->value);
        if ($newlineFormatOption !== null) {
            $formatValue = strtolower($newlineFormatOption);
            $format = NewlineFormat::tryFrom($formatValue) ?? NewlineFormat::Lf;
            $config[SanitizerConfigKeys::NewlineFormat] = $format->getCharacter();
        }

        return $config;
    }

    /**
     * Normalize sanitizer configuration array to use enum keys.
     *
     * Ensures all config keys use the SanitizerConfigKeys enum values
     * and converts newline_format to character if needed.
     * If config is empty or incomplete, loads defaults from package config file.
     *
     * @param  array<string, mixed>  $config  Raw config array
     * @return array<string, mixed> Normalized config array
     */
    private function normalizeSanitizerConfig(array $config): array
    {
        // If config is empty, load from package config file
        if (empty($config)) {
            $configPath = dirname(__DIR__, 3).'/config/inflow.php';
            $packageConfig = require $configPath;
            $config = $packageConfig['sanitizer'] ?? [];
        }

        $normalized = [];

        // Map config keys to normalized keys (both old and new keys map to the same key strings)
        foreach (SanitizerConfigKeys::all() as $keyValue) {

            // Check if config has this key (either old or new format)
            if (isset($config[$keyValue])) {
                $value = $config[$keyValue];

                // Convert newline_format string to character if needed
                if ($keyValue === SanitizerConfigKeys::NewlineFormat && is_string($value) && strlen($value) > 2) {
                    $format = NewlineFormat::tryFrom(strtolower($value)) ?? NewlineFormat::Lf;
                    $value = $format->getCharacter();
                }

                $normalized[$keyValue] = $value;
            }
        }

        // If normalized config is incomplete, merge with package defaults
        if (count($normalized) < count(SanitizerConfigKeys::all())) {
            $configPath = dirname(__DIR__, 3).'/config/inflow.php';
            $packageConfig = require $configPath;
            $packageDefaults = $packageConfig['sanitizer'] ?? [];

            // Normalize package defaults and merge
            foreach (SanitizerConfigKeys::all() as $keyValue) {
                if (! isset($normalized[$keyValue]) && isset($packageDefaults[$keyValue])) {
                    $value = $packageDefaults[$keyValue];

                    // Convert newline_format string to character if needed
                    if ($keyValue === SanitizerConfigKeys::NewlineFormat && is_string($value) && strlen($value) > 2) {
                        $format = NewlineFormat::tryFrom(strtolower($value)) ?? NewlineFormat::Lf;
                        $value = $format->getCharacter();
                    }

                    $normalized[$keyValue] = $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * Generate mapping file path from model FQCN.
     *
     * Converts a fully qualified class name to a filesystem-safe path.
     * Example: App\Models\User -> mappings/App_Models_User.json
     *
     * @param  string  $modelClass  The fully qualified model class name
     * @param  string  $extension  File extension (default: 'json')
     * @return string The mapping file path
     */
    public function getMappingPathFromModel(string $modelClass, string $extension = 'json'): string
    {
        // Convert FQCN to filesystem-safe path: App\Models\User -> App_Models_User.json
        $path = str_replace('\\', '_', $modelClass);

        return "mappings/{$path}.{$extension}";
    }

    /**
     * Find existing mapping file for a model.
     *
     * Searches for mapping files in JSON format.
     * Returns the path to the mapping file, or null if not found.
     *
     * @param  string  $modelClass  The fully qualified model class name
     * @return string|null The path to the mapping file, or null if not found
     */
    public function findMappingForModel(string $modelClass): ?string
    {
        $jsonPath = $this->getMappingPathFromModel($modelClass);
        if (file_exists($jsonPath)) {
            return $jsonPath;
        }

        return null;
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
     * @param  callable  $getCommandOption  Callback to get command option value
     * @return bool True if this appears to be a first-time setup, false otherwise
     */
    public function isFirstTimeSetup(callable $getCommandOption): bool
    {
        $significantKeys = [
            ConfigKey::Sanitize,
            ConfigKey::Mapping,
            ConfigKey::NewlineFormat,
        ];

        foreach ($significantKeys as $key) {
            if ($getCommandOption($key->value) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get reader configuration value.
     *
     * Retrieves a value from the reader configuration section.
     * Tries Laravel config first, then falls back to package default config file.
     *
     * @param  string  $key  The configuration key (e.g., 'chunk_size', 'streaming')
     * @param  mixed  $default  Default value if not found in config
     * @return mixed The configuration value or default
     */
    public function getReaderConfig(string $key, mixed $default = null): mixed
    {
        // Try to load from Laravel config first
        if (function_exists('config')) {
            $value = config("inflow.reader.{$key}");
            if ($value !== null) {
                return $value;
            }
        }

        // If not available, use package default
        $configPath = dirname(__DIR__, 3).'/config/inflow.php';
        if (file_exists($configPath)) {
            $packageConfig = require $configPath;
            $value = $packageConfig['reader'][$key] ?? null;
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Resolve option value with fallback to config default.
     *
     * Similar to resolveOption but without guided config, useful for simple option resolution.
     * Priority order:
     * 1. Command option (explicit override)
     * 2. Config file default
     * 3. Provided fallback value
     *
     * @param  string  $key  The option key to resolve
     * @param  callable  $getCommandOption  Callback to get command option value
     * @param  mixed  $fallback  Fallback value if not found in config
     * @return mixed The resolved option value
     */
    public function resolveOptionWithFallback(string $key, callable $getCommandOption, mixed $fallback = null): mixed
    {
        // First check command option (explicit override)
        $optionValue = $getCommandOption($key);
        if ($optionValue !== null) {
            return $optionValue;
        }

        // Then check config file default
        $configDefault = $this->getConfigDefault($key);
        if ($configDefault !== null) {
            return $configDefault;
        }

        // Finally, use provided fallback
        return $fallback;
    }
}
