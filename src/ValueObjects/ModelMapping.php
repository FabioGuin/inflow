<?php

namespace InFlow\ValueObjects;

/**
 * Value Object representing a model mapping
 *
 * Supports:
 * - type: "model" (default) - standard model mapping
 * - type: "pivot_sync" - sync many-to-many relations only
 */
readonly class ModelMapping
{
    /**
     * @param  array<ColumnMapping>  $columns
     * @param  array<string, mixed>  $options
     * @param  int  $executionOrder  Order of execution (1 = first, 2 = second, etc.)
     * @param  string  $type  Type of mapping: "model" (default) or "pivot_sync"
     * @param  string|null  $relationPath  For pivot_sync: relation path (e.g., "Book.tags")
     */
    public function __construct(
        public string $modelClass,
        public array $columns,
        public array $options = [],
        public int $executionOrder = 1,
        public string $type = 'model',
        public ?string $relationPath = null
    ) {}

    /**
     * Returns the mapping as an array
     */
    public function toArray(): array
    {
        $data = [
            'model' => $this->modelClass,
            'columns' => array_map(
                fn (ColumnMapping $col) => $col->toArray(),
                $this->columns
            ),
            'options' => $this->options,
            'execution_order' => $this->executionOrder,
        ];

        if ($this->type !== 'model') {
            $data['type'] = $this->type;
        }

        if ($this->relationPath !== null) {
            $data['relation_path'] = $this->relationPath;
        }

        return $data;
    }

    /**
     * Creates a ModelMapping from an array
     */
    public static function fromArray(array $data): self
    {
        $modelClass = $data['model'] ?? '';

        // Normalize model class: fix corrupted backslashes (e.g., App$2odels$2ser -> App\Models\User)
        // This can happen if the model class was saved incorrectly
        if (str_contains($modelClass, '$2')) {
            // Replace $2 with backslash (common corruption pattern)
            $modelClass = str_replace('$2', '\\', $modelClass);
        }

        // Also handle other potential corruptions
        $modelClass = str_replace('\\\\', '\\', $modelClass);

        return new self(
            modelClass: $modelClass,
            columns: array_map(
                fn (array $col) => ColumnMapping::fromArray($col),
                $data['columns'] ?? []
            ),
            options: $data['options'] ?? [],
            executionOrder: $data['execution_order'] ?? 1,
            type: $data['type'] ?? 'model',
            relationPath: $data['relation_path'] ?? null
        );
    }

    /**
     * Gets a column mapping by source column name
     */
    public function getColumnBySource(string $sourceColumn): ?ColumnMapping
    {
        foreach ($this->columns as $column) {
            if ($column->sourceColumn === $sourceColumn) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Gets a column mapping by target path
     */
    public function getColumnByTarget(string $targetPath): ?ColumnMapping
    {
        foreach ($this->columns as $column) {
            if ($column->targetPath === $targetPath) {
                return $column;
            }
        }

        return null;
    }
}
