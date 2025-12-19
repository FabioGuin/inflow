<?php

namespace InFlow\ValueObjects;

/**
 * Value Object representing a row of tabular data
 */
readonly class Row
{
    public function __construct(
        public array $data,
        public int $lineNumber
    ) {}

    /**
     * Returns the value of a specific column
     */
    public function get(string $column): mixed
    {
        return $this->data[$column] ?? null;
    }

    /**
     * Checks if a column exists
     */
    public function has(string $column): bool
    {
        return isset($this->data[$column]);
    }

    /**
     * Returns all data as an associative array
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Check if the row is empty (all values are null, empty string, or whitespace)
     */
    public function isEmpty(): bool
    {
        if (empty($this->data)) {
            return true;
        }

        foreach ($this->data as $value) {
            if ($value !== null && $value !== '' && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
