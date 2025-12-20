<?php

namespace InFlow\Mappings;

use InFlow\ValueObjects\Data\ColumnMapping;
use InFlow\ValueObjects\Mapping\ModelMapping;

/**
 * Fluent builder for model mappings.
 *
 * Extracted from `MappingBuilder.php` to keep responsibilities separated:
 * - `MappingBuilder`: auto-mapping orchestration and helper services
 * - `ModelMappingBuilder`: DSL/fluent API for manually defining mappings
 */
class ModelMappingBuilder
{
    /**
     * @var array<int, ColumnMapping>
     */
    private array $columns = [];

    /**
     * @var array<string, mixed>
     */
    private array $options = [];

    public function __construct(
        private readonly string $modelClass
    ) {}

    /**
     * Map a source column to a target path.
     *
     * @param  string|array<string>|null  $transforms
     */
    public function map(
        string $sourceColumn,
        string $targetPath,
        string|array|null $transforms = null,
        mixed $default = null,
        ?string $validationRule = null,
        ?array $relationLookup = null
    ): self {
        $transformArray = is_string($transforms) ? explode('|', $transforms) : ($transforms ?? []);

        $this->columns[] = new ColumnMapping(
            sourceColumn: $sourceColumn,
            targetPath: $targetPath,
            transforms: $transformArray,
            default: $default,
            validationRule: $validationRule,
            relationLookup: $relationLookup
        );

        return $this;
    }

    /**
     * Set options for the model mapping (DSL method for configuration).
     *
     * @param  array<string, mixed>  $options  Mapping options (e.g., ['update_on_duplicate' => true])
     */
    public function options(array $options): self
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    /**
     * Build and finalize the ModelMapping (DSL terminal method).
     */
    public function build(): ModelMapping
    {
        return new ModelMapping(
            modelClass: $this->modelClass,
            columns: $this->columns,
            options: $this->options
        );
    }
}
