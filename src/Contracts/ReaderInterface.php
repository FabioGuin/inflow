<?php

namespace InFlow\Contracts;

use Iterator;

/**
 * Interface for tabular file readers (CSV, Excel, etc.)
 */
interface ReaderInterface extends Iterator
{
    /**
     * Returns the current row as an associative array
     */
    public function current(): array;

    /**
     * Moves to the next element
     */
    public function next(): void;

    /**
     * Returns the current key
     */
    public function key(): int;

    /**
     * Checks if the current position is valid
     */
    public function valid(): bool;

    /**
     * Rewinds to the beginning
     */
    public function rewind(): void;
}
