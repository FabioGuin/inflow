<?php

namespace InFlow\ValueObjects\Data;

/**
 * Value Object representing the inferred schema of a data source
 */
readonly class SourceSchema
{
    /**
     * @param  array<string, ColumnMetadata>  $columns
     */
    public function __construct(
        public array $columns,
        public int $totalRows
    ) {}

    /**
     * Returns metadata for a specific column
     */
    public function getColumn(string $name): ?ColumnMetadata
    {
        return $this->columns[$name] ?? null;
    }

    /**
     * Returns names of all columns
     */
    public function getColumnNames(): array
    {
        return array_keys($this->columns);
    }

    /**
     * Returns the schema as an array
     */
    public function toArray(): array
    {
        return [
            'columns' => array_map(
                fn (ColumnMetadata $col) => $col->toArray(),
                $this->columns
            ),
            'total_rows' => $this->totalRows,
        ];
    }
}
