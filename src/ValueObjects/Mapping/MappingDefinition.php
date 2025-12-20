<?php

namespace InFlow\ValueObjects\Mapping;

use InFlow\Enums\Data\ColumnType;
use InFlow\ValueObjects\Data\ColumnMetadata;
use InFlow\ValueObjects\Data\SourceSchema;

/**
 * Value Object representing a complete mapping definition
 *
 * This is the result of the DSL (Domain-Specific Language) mapping definition.
 * It can be created either programmatically using MappingBuilder's fluent interface
 * or loaded from a serialized JSON file for reuse.
 *
 * The mapping definition is a persistent entity that can be saved and reused
 * for recurring imports of the same file type, following the DRY principle.
 *
 * @see MappingBuilder For DSL-like fluent interface to create mappings
 * @see MappingSerializer For serialization/deserialization to/from files
 */
readonly class MappingDefinition
{
    /**
     * @param  array<ModelMapping>  $mappings
     */
    public function __construct(
        public array $mappings,
        public string $name = '',
        public ?string $description = null,
        public ?SourceSchema $sourceSchema = null
    ) {}

    /**
     * Returns the mapping definition as an array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'source_schema' => $this->sourceSchema?->toArray(),
            'mappings' => array_map(
                fn (ModelMapping $mapping) => $mapping->toArray(),
                $this->mappings
            ),
        ];
    }

    /**
     * Creates a MappingDefinition from an array
     */
    public static function fromArray(array $data): self
    {
        $sourceSchema = null;
        if (isset($data['source_schema']) && is_array($data['source_schema'])) {
            $schemaData = $data['source_schema'];
            $columns = [];

            // Handle both array of column objects and array with 'columns' key
            $columnsData = $schemaData['columns'] ?? $schemaData;

            if (is_array($columnsData)) {
                foreach ($columnsData as $colData) {
                    if (is_array($colData)) {
                        $type = is_string($colData['type'])
                            ? ColumnType::tryFrom($colData['type']) ?? ColumnType::String
                            : $colData['type'];

                        $columns[$colData['name']] = new ColumnMetadata(
                            name: $colData['name'],
                            type: $type,
                            nullCount: $colData['null_count'] ?? 0,
                            uniqueCount: $colData['unique_count'] ?? 0,
                            min: $colData['min'] ?? null,
                            max: $colData['max'] ?? null,
                            examples: $colData['examples'] ?? []
                        );
                    }
                }
            }

            $sourceSchema = new SourceSchema(
                columns: $columns,
                totalRows: $schemaData['total_rows'] ?? 0
            );
        }

        return new self(
            mappings: array_map(
                fn (array $mapping) => ModelMapping::fromArray($mapping),
                $data['mappings'] ?? []
            ),
            name: $data['name'] ?? '',
            description: $data['description'] ?? null,
            sourceSchema: $sourceSchema
        );
    }

    /**
     * Gets a model mapping by model class
     */
    public function getModelMapping(string $modelClass): ?ModelMapping
    {
        foreach ($this->mappings as $mapping) {
            if ($mapping->modelClass === $modelClass) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * Validates that all source columns exist in the source schema
     */
    public function validateSourceColumns(?SourceSchema $schema): array
    {
        if ($schema === null) {
            return [];
        }

        $errors = [];
        $availableColumns = $schema->getColumnNames();

        foreach ($this->mappings as $modelMapping) {
            foreach ($modelMapping->columns as $column) {
                if (! in_array($column->sourceColumn, $availableColumns, true)) {
                    $errors[] = "Source column '{$column->sourceColumn}' not found in source schema";
                }
            }
        }

        return $errors;
    }
}
