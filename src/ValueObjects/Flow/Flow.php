<?php

namespace InFlow\ValueObjects\Flow;

use InFlow\Enums\Flow\ErrorPolicy;

/**
 * Value Object representing a complete, reusable ETL flow configuration
 *
 * A Flow is a persistent, reusable configuration that defines:
 * - Source configuration (file path, type, etc.)
 * - Sanitization settings
 * - Format detection overrides (optional)
 * - Mapping definition
 * - Execution options (chunk size, error policy, etc.)
 *
 * Once defined and saved, a Flow can be executed multiple times on different
 * files of the same type, ensuring consistency and reusability.
 */
readonly class Flow
{
    /**
     * @param  array<string, mixed>  $sourceConfig  Source configuration (e.g., ['path' => '...', 'type' => 'file'])
     * @param  array<string, mixed>  $sanitizerConfig  Sanitization settings (BOM removal, control chars, newlines)
     * @param  array<string, mixed>|null  $formatConfig  Format detection overrides (optional, null = auto-detect)
     * @param  mixed|null  $mapping  Mapping definition - TODO: Re-implement with new mapping system
     * @param  array<string, mixed>  $options  Execution options (chunk_size, error_policy, etc.)
     * @param  string  $name  Flow name for identification
     * @param  string|null  $description  Optional description
     */
    public function __construct(
        public array $sourceConfig,
        public array $sanitizerConfig,
        public ?array $formatConfig,
        public mixed $mapping = null, // TODO: Re-implement with new mapping system
        public array $options,
        public string $name,
        public ?string $description = null
    ) {}

    /**
     * Get chunk size for processing (default: 1000)
     */
    public function getChunkSize(): int
    {
        return $this->options['chunk_size'] ?? 1000;
    }

    /**
     * Get error policy: 'stop' (fail on first error) or 'continue' (collect errors and continue)
     */
    public function getErrorPolicy(): string
    {
        return $this->options['error_policy'] ?? ErrorPolicy::Continue->value;
    }

    /**
     * Check if flow should stop on first error
     */
    public function shouldStopOnError(): bool
    {
        return $this->getErrorPolicy() === ErrorPolicy::Stop->value;
    }

    /**
     * Check if empty rows should be skipped
     */
    public function shouldSkipEmptyRows(): bool
    {
        return $this->options['skip_empty_rows'] ?? true;
    }

    /**
     * Check if long fields should be truncated
     */
    public function shouldTruncateLongFields(): bool
    {
        return $this->options['truncate_long_fields'] ?? true;
    }

    /**
     * Convert Flow to array for serialization
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'source_config' => $this->sourceConfig,
            'sanitizer_config' => $this->sanitizerConfig,
            'format_config' => $this->formatConfig,
            'mapping' => null, // TODO: Re-implement with new mapping system
            'options' => $this->options,
        ];
    }

    /**
     * Create Flow from array (deserialization)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceConfig: $data['source_config'] ?? [],
            sanitizerConfig: $data['sanitizer_config'] ?? [],
            formatConfig: $data['format_config'] ?? null,
            mapping: null, // TODO: Re-implement with new mapping system
            options: $data['options'] ?? [],
            name: $data['name'] ?? 'Unnamed Flow',
            description: $data['description'] ?? null
        );
    }

    /**
     * Validate flow configuration
     *
     * @return array<string> Array of validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors[] = 'Flow name is required';
        }

        if (empty($this->sourceConfig)) {
            $errors[] = 'Source configuration is required';
        }

        if (empty($this->sanitizerConfig)) {
            $errors[] = 'Sanitizer configuration is required';
        }

        $chunkSize = $this->getChunkSize();
        if ($chunkSize < 1 || $chunkSize > 100000) {
            $errors[] = 'Chunk size must be between 1 and 100000';
        }

        $errorPolicy = $this->getErrorPolicy();
        if (! ErrorPolicy::isValid($errorPolicy)) {
            $errors[] = 'Error policy must be either "stop" or "continue"';
        }

        return $errors;
    }
}
