<?php

namespace InFlow\Services\Mapping;

use InFlow\Enums\MappingHistoryAction;

/**
 * Service for mapping history management.
 *
 * Handles the business logic of tracking mapping history for undo functionality.
 * Presentation logic (displaying history, prompts) is handled by the caller.
 */
class MappingHistoryService
{
    /**
     * Add entry to mapping history.
     *
     * @param  array<int, array{column: string, path: string|null, action: string}>  $history  The history array (by reference)
     * @param  int  $currentIndex  The current index (by reference)
     * @param  string  $column  The column name
     * @param  string|null  $path  The mapping path
     * @param  MappingHistoryAction  $action  The action
     * @return int The new current index
     */
    public function addEntry(
        array &$history,
        int &$currentIndex,
        string $column,
        ?string $path,
        MappingHistoryAction $action
    ): int {
        $currentIndex++;
        $history[$currentIndex] = [
            'column' => $column,
            'path' => $path,
            'action' => $action->value,
        ];

        return $currentIndex;
    }

    /**
     * Update entry in mapping history.
     *
     * @param  array<int, array{column: string, path: string|null, action: string}>  $history  The history array (by reference)
     * @param  int  $index  The index to update
     * @param  string|null  $path  The new mapping path
     * @param  MappingHistoryAction  $action  The new action
     */
    public function updateEntry(
        array &$history,
        int $index,
        ?string $path,
        MappingHistoryAction $action
    ): void {
        if (! isset($history[$index])) {
            return;
        }

        $history[$index]['path'] = $path;
        $history[$index]['action'] = $action->value;
    }

    /**
     * Format history entry for display.
     *
     * @param  array{column: string, path: string|null, action: string}  $entry  The history entry
     * @return string Formatted entry string
     */
    public function formatEntryForDisplay(array $entry): string
    {
        $action = MappingHistoryAction::tryFrom($entry['action'] ?? '');
        $status = $action?->getDisplaySymbol() ?? '?';

        $path = $entry['path'] ?? '(skipped)';

        return "{$status} {$entry['column']} → {$path}";
    }

    /**
     * Check if there are previous entries in history.
     *
     * @param  int  $currentIndex  The current index
     * @return bool True if there are previous entries, false otherwise
     */
    public function hasPrevious(int $currentIndex): bool
    {
        return $currentIndex >= 0;
    }
}
