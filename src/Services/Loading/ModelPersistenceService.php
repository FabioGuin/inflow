<?php

namespace InFlow\Services\Loading;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use InFlow\Enums\Data\DuplicateStrategy;

/**
 * Service for persisting models with duplicate handling strategies.
 *
 * Handles business logic for:
 * - Creating or updating models based on duplicate strategy
 * - Handling duplicate key errors
 * - Extracting duplicate fields from errors
 *
 * Presentation logic (logging, errors) is handled by the caller.
 */
readonly class ModelPersistenceService
{
    /**
     * Create or update a model based on options.
     *
     * Business logic: creates or updates model based on unique key and duplicate strategy.
     *
     * @param  class-string<Model>  $modelClass  The model class
     * @param  array<string, mixed>  $attributes  The model attributes
     * @param  array<string, mixed>  $options  The options (unique_key, duplicate_strategy)
     * @return Model|null Returns null if duplicate strategy is 'skip' and record exists
     *
     * @throws \RuntimeException If duplicate strategy is 'error' and duplicate found
     * @throws QueryException If database error occurs
     */
    public function createOrUpdate(string $modelClass, array $attributes, array $options): ?Model
    {
        $uniqueKey = $this->normalizeUniqueKey($options['unique_key'] ?? null);
        $duplicateStrategy = $this->parseDuplicateStrategy($options['duplicate_strategy'] ?? 'error');

        if ($uniqueKey === null) {
            return $this->createWithoutUniqueKey($modelClass, $attributes, $duplicateStrategy);
        }

        return $this->createWithUniqueKey($modelClass, $attributes, $uniqueKey, $duplicateStrategy);
    }

    /**
     * Create model without unique key configured.
     *
     * Business logic: creates model, handles duplicate errors based on strategy.
     *
     * @param  class-string<Model>  $modelClass  The model class
     * @param  array<string, mixed>  $attributes  The model attributes
     * @param  DuplicateStrategy  $duplicateStrategy  The duplicate strategy
     * @return Model|null Returns null if duplicate strategy is 'skip' and duplicate found
     *
     * @throws QueryException If database error occurs
     */
    private function createWithoutUniqueKey(string $modelClass, array $attributes, DuplicateStrategy $duplicateStrategy): ?Model
    {
        try {
            return $modelClass::create($attributes);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKeyError($e)) {
                throw $e;
            }

            return $this->handleDuplicateError($e, $modelClass, $attributes, $duplicateStrategy);
        }
    }

    /**
     * Create model with unique key configured.
     *
     * Business logic: checks for existing record, creates or updates based on strategy.
     *
     * @param  class-string<Model>  $modelClass  The model class
     * @param  array<string, mixed>  $attributes  The model attributes
     * @param  array<string>  $uniqueKey  The unique key field names
     * @param  DuplicateStrategy  $duplicateStrategy  The duplicate strategy
     * @return Model|null Returns null if duplicate strategy is 'skip' and duplicate found
     *
     * @throws \RuntimeException If duplicate strategy is 'error' and duplicate found
     * @throws QueryException If database error occurs
     */
    private function createWithUniqueKey(string $modelClass, array $attributes, array $uniqueKey, DuplicateStrategy $duplicateStrategy): ?Model
    {
        $query = $modelClass::query();

        foreach ($uniqueKey as $key) {
            $value = $attributes[$key] ?? null;
            if ($value === null) {
                return $this->createNewModel($modelClass, $attributes, $duplicateStrategy);
            }
            $query->where($key, $value);
        }

        $existing = $query->first();

        if ($existing !== null) {
            return $this->handleExistingRecord($existing, $attributes, $duplicateStrategy, $uniqueKey);
        }

        return $this->createNewModel($modelClass, $attributes, $duplicateStrategy);
    }

    /**
     * Create new model, handling duplicate errors.
     *
     * Business logic: creates model, handles duplicate errors based on strategy.
     *
     * @param  class-string<Model>  $modelClass  The model class
     * @param  array<string, mixed>  $attributes  The model attributes
     * @param  DuplicateStrategy  $duplicateStrategy  The duplicate strategy
     * @return Model|null Returns null if duplicate strategy is 'skip' and duplicate found
     *
     * @throws QueryException If database error occurs
     */
    private function createNewModel(string $modelClass, array $attributes, DuplicateStrategy $duplicateStrategy): ?Model
    {
        try {
            return $modelClass::create($attributes);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKeyError($e)) {
                throw $e;
            }

            return $this->handleDuplicateError($e, $modelClass, $attributes, $duplicateStrategy);
        }
    }

    /**
     * Handle existing record based on duplicate strategy.
     *
     * Business logic: applies duplicate strategy to existing record.
     *
     * @param  Model  $existing  The existing model
     * @param  array<string, mixed>  $attributes  The new attributes
     * @param  DuplicateStrategy  $duplicateStrategy  The duplicate strategy
     * @param  array<string>  $uniqueKey  The unique key field names
     * @return Model|null Returns null if duplicate strategy is 'skip'
     *
     * @throws \RuntimeException If duplicate strategy is 'error'
     */
    private function handleExistingRecord(Model $existing, array $attributes, DuplicateStrategy $duplicateStrategy, array $uniqueKey): ?Model
    {
        return match ($duplicateStrategy) {
            DuplicateStrategy::Skip => null,
            DuplicateStrategy::Update => $this->updateModel($existing, $attributes),
            DuplicateStrategy::Error => throw new \RuntimeException($this->formatDuplicateErrorMessage($uniqueKey, $attributes)),
        };
    }

    /**
     * Format duplicate error message for unique keys.
     *
     * @param  array<string>  $uniqueKey  The unique key field names
     * @param  array<string, mixed>  $attributes  The attributes
     * @return string Formatted error message
     */
    private function formatDuplicateErrorMessage(array $uniqueKey, array $attributes): string
    {
        $pairs = [];
        foreach ($uniqueKey as $key) {
            $value = $attributes[$key] ?? null;
            $pairs[] = "{$key}=".($value === null ? 'null' : (string) $value);
        }

        return 'Duplicate record found for unique key: '.implode(', ', $pairs);
    }

    /**
     * Normalize unique key to array format.
     *
     * @param  string|array<string>|null  $uniqueKey  The unique key (string, array, or null)
     * @return array<string>|null Normalized array or null
     */
    private function normalizeUniqueKey(string|array|null $uniqueKey): ?array
    {
        if ($uniqueKey === null) {
            return null;
        }

        return is_array($uniqueKey) ? $uniqueKey : [$uniqueKey];
    }

    /**
     * Handle duplicate key error based on strategy.
     *
     * Business logic: handles duplicate error based on strategy.
     *
     * @param  QueryException  $e  The duplicate key exception
     * @param  class-string<Model>  $modelClass  The model class
     * @param  array<string, mixed>  $attributes  The model attributes
     * @param  DuplicateStrategy  $duplicateStrategy  The duplicate strategy
     * @return Model|null Returns null if duplicate strategy is 'skip'
     *
     * @throws QueryException If strategy is 'error' or update fails
     */
    private function handleDuplicateError(QueryException $e, string $modelClass, array $attributes, DuplicateStrategy $duplicateStrategy): ?Model
    {
        return match ($duplicateStrategy) {
            DuplicateStrategy::Skip => null,
            DuplicateStrategy::Update => $this->updateFromDuplicateError($e, $modelClass, $attributes),
            DuplicateStrategy::Error => throw $e,
        };
    }

    /**
     * Update model from duplicate error.
     *
     * Business logic: extracts duplicate field from error and updates existing model.
     *
     * @param  QueryException  $e  The duplicate key exception
     * @param  class-string<Model>  $modelClass  The model class
     * @param  array<string, mixed>  $attributes  The model attributes
     * @return Model|null Returns updated model, or null if not found
     *
     * @throws QueryException If update fails
     */
    private function updateFromDuplicateError(QueryException $e, string $modelClass, array $attributes): ?Model
    {
        // Try to find by primary key first
        $model = new $modelClass;
        $primaryKey = $model->getKeyName();

        if (isset($attributes[$primaryKey])) {
            $existing = $modelClass::find($attributes[$primaryKey]);
            if ($existing !== null) {
                $existing->update($attributes);

                return $existing;
            }
        }

        // Try to extract duplicate field from error message
        $duplicateField = $this->extractDuplicateFieldFromError($e);
        if ($duplicateField !== null && isset($attributes[$duplicateField])) {
            $existing = $modelClass::where($duplicateField, $attributes[$duplicateField])->first();
            if ($existing !== null) {
                $existing->update($attributes);

                return $existing;
            }
        }

        // Can't update - re-throw error
        throw $e;
    }

    /**
     * Update model with new attributes.
     *
     * Business logic: updates model attributes.
     *
     * @param  Model  $model  The model to update
     * @param  array<string, mixed>  $attributes  The new attributes
     * @return Model The updated model
     */
    private function updateModel(Model $model, array $attributes): Model
    {
        $model->update($attributes);

        return $model;
    }

    /**
     * Parse duplicate strategy from string.
     *
     * Business logic: converts string to enum.
     *
     * @param  string  $strategy  The strategy string
     * @return DuplicateStrategy The duplicate strategy enum
     */
    private function parseDuplicateStrategy(string $strategy): DuplicateStrategy
    {
        return DuplicateStrategy::tryFrom($strategy) ?? DuplicateStrategy::Error;
    }

    /**
     * Check if exception is a duplicate key error.
     *
     * Business logic: determines if exception is a duplicate key error.
     *
     * @param  QueryException  $e  The exception
     * @return bool True if it's a duplicate key error
     */
    private function isDuplicateKeyError(QueryException $e): bool
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // MySQL duplicate key error codes
        // 1062 = Duplicate entry
        // 23000 = Integrity constraint violation (SQLSTATE)
        return $code === 1062
            || $code === 23000
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint')
            || str_contains($message, 'duplicate key');
    }

    /**
     * Extract the duplicate field name from error message.
     *
     * Business logic: parses error message to extract duplicate field name.
     *
     * @param  QueryException  $e  The exception
     * @return string|null The duplicate field name, or null if not found
     */
    private function extractDuplicateFieldFromError(QueryException $e): ?string
    {
        $message = $e->getMessage();

        // Pattern: "Duplicate entry 'value' for key 'table.table_field_unique'"
        // Extract field name from key name (e.g., "users_email_unique" -> "email")
        if (preg_match("/for key '([^']+)'/", $message, $matches)) {
            $keyName = $matches[1];
            // Extract field name from key (e.g., "users.users_email_unique" -> "email")
            if (preg_match('/_([^_]+)_unique$/', $keyName, $fieldMatches)) {
                return $fieldMatches[1];
            }
            // Alternative pattern: "table_field_unique" -> "field"
            if (preg_match('/(?:\.|_)([^_.]+)_unique$/', $keyName, $fieldMatches)) {
                return $fieldMatches[1];
            }
        }

        return null;
    }
}
