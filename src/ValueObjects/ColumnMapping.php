<?php

namespace InFlow\ValueObjects;

/**
 * Value Object representing a single column mapping
 */
readonly class ColumnMapping
{
    /**
     * @param  array<string>  $transforms
     * @param  array<string, mixed>|null  $relationLookup  Configuration for relation lookup (e.g., ['field' => 'name', 'create_if_missing' => true])
     */
    public function __construct(
        public string $sourceColumn,
        public string $targetPath,
        public array $transforms = [],
        public mixed $default = null,
        public ?string $validationRule = null,
        public ?array $relationLookup = null
    ) {}

    /**
     * Returns the mapping as an array
     */
    public function toArray(): array
    {
        return [
            'source' => $this->sourceColumn,
            'target' => $this->targetPath,
            'transforms' => $this->transforms,
            'default' => $this->default,
            'validation_rule' => $this->validationRule,
            'relation_lookup' => $this->relationLookup,
        ];
    }

    /**
     * Creates a ColumnMapping from an array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceColumn: $data['source'],
            targetPath: $data['target'],
            transforms: $data['transforms'] ?? [],
            default: $data['default'] ?? null,
            validationRule: $data['validation_rule'] ?? null,
            relationLookup: $data['relation_lookup'] ?? null
        );
    }

    /**
     * Checks if the target path is a nested relation
     */
    public function isNested(): bool
    {
        return str_contains($this->targetPath, '.');
    }

    /**
     * Parses the target path into parts (e.g., "address.street" -> ["address", "street"])
     *
     * @return array{0: string, 1: string}|array{0: string}
     */
    public function parsePath(): array
    {
        return explode('.', $this->targetPath);
    }

    /**
     * Checks if the target path has optional parts (starts with ?)
     */
    public function hasOptionalParts(): bool
    {
        return str_contains($this->targetPath, '?');
    }
}
