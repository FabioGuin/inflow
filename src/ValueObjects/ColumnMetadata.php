<?php

namespace InFlow\ValueObjects;

use InFlow\Enums\ColumnType;

/**
 * Value Object representing column metadata
 */
readonly class ColumnMetadata
{
    public function __construct(
        public string $name,
        public ColumnType $type,
        public int $nullCount,
        public int $uniqueCount,
        public mixed $min = null,
        public mixed $max = null,
        public array $examples = []
    ) {}

    /**
     * Returns the percentage of null/empty values
     */
    public function getNullPercentage(int $totalRows): float
    {
        if ($totalRows === 0) {
            return 0.0;
        }

        return ($this->nullCount / $totalRows) * 100;
    }

    /**
     * Returns metadata as an array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'null_count' => $this->nullCount,
            'unique_count' => $this->uniqueCount,
            'min' => $this->min,
            'max' => $this->max,
            'examples' => $this->examples,
        ];
    }
}
