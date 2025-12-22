<?php

namespace InFlow\Services\Core;

use InFlow\Enums\Config\ConfigKey;
use InFlow\Enums\File\NewlineFormat;
use InFlow\Sanitizers\SanitizerConfigKeys;

class ConfigurationResolver
{
    /**
     * Path to the package default config file.
     */
    private readonly string $packageConfigPath;

    /**
     * Directory path where mapping files are stored.
     */
    private readonly string $mappingsPath;

    public function __construct()
    {
        $this->packageConfigPath = dirname(__DIR__, 3).'/config/inflow.php';

        // Load mappings path from config with fallback to default
        $this->mappingsPath = $this->resolveMappingsPath();
    }

    /**
     * Resolve mappings directory path from config.
     */
    private function resolveMappingsPath(): string
    {
        // Try to load from Laravel config first
        if (function_exists('config')) {
            $path = config('inflow.mappings.path');
            if ($path !== null) {
                return rtrim($path, '/');
            }
        }

        // Fall back to package config
        $packagePath = $this->getPackageConfigValue('mappings', 'path');
        if ($packagePath !== null) {
            return rtrim($packagePath, '/');
        }

        // Default fallback
        return 'mappings';
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
            $config = $this->getPackageConfigSection('sanitizer') ?? [];
        }

        // Normalize and return config
        return $this->normalizeSanitizerConfig($config);
    }

    /**
     * Get sanitizer configuration value.
     *
     * Retrieves a value from the sanitizer configuration section.
     * Tries Laravel config first, then falls back to package default config file.
     *
     * @param  string  $key  The configuration key (e.g., 'enabled', 'remove_bom', 'newline_format')
     * @param  mixed  $default  Default value if not found in config
     * @return mixed The configuration value or default
     */
    public function getSanitizerConfig(string $key, mixed $default = null): mixed
    {
        // Try to load from Laravel config first
        if (function_exists('config')) {
            $value = config("inflow.sanitizer.{$key}");
            if ($value !== null) {
                return $value;
            }
        }

        // If not available, use package default
        $value = $this->getPackageConfigValue('sanitizer', $key);
        if ($value !== null) {
            return $value;
        }

        return $default;
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
            $config = $this->getPackageConfigSection('sanitizer') ?? [];
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
            $packageDefaults = $this->getPackageConfigSection('sanitizer') ?? [];

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

        return "{$this->mappingsPath}/{$path}.{$extension}";
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
        $value = $this->getPackageConfigValue('reader', $key);
        if ($value !== null) {
            return $value;
        }

        return $default;
    }

    /**
     * Get execution configuration.
     *
     * Retrieves execution configuration from Laravel config or package default config file.
     *
     * @param  string  $key  The configuration key (e.g., 'error_policy', 'skip_empty_rows', 'truncate_long_fields')
     * @param  mixed  $default  Default value if not found in config
     * @return mixed The configuration value or default
     */
    public function getExecutionConfig(string $key, mixed $default = null): mixed
    {
        // Try to load from Laravel config first
        if (function_exists('config')) {
            $value = config("inflow.execution.{$key}");
            if ($value !== null) {
                return $value;
            }
        }

        // If not available, use package default
        $value = $this->getPackageConfigValue('execution', $key);
        if ($value !== null) {
            return $value;
        }

        return $default;
    }

    /**
     * Get a value from package default config file.
     *
     * @param  string  $section  The config section (e.g., 'command', 'reader', 'execution')
     * @param  string  $key  The config key within the section
     * @return mixed The config value or null if not found
     */
    private function getPackageConfigValue(string $section, string $key): mixed
    {
        if (file_exists($this->packageConfigPath)) {
            $packageConfig = require $this->packageConfigPath;
            $value = $packageConfig[$section][$key] ?? null;
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Get an entire section from package default config file.
     *
     * @param  string  $section  The config section (e.g., 'sanitizer', 'reader', 'execution')
     * @return array<string, mixed>|null The config section or null if not found
     */
    private function getPackageConfigSection(string $section): ?array
    {
        if (file_exists($this->packageConfigPath)) {
            $packageConfig = require $this->packageConfigPath;

            return $packageConfig[$section] ?? null;
        }

        return null;
    }
}
