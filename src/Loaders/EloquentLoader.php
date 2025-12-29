<?php

namespace InFlow\Loaders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InFlow\Enums\Data\DuplicateStrategy;
use InFlow\Enums\Data\EloquentRelationType;
use InFlow\Exceptions\RelationResolutionException;
use InFlow\Services\Loading\RelationTypeService;
use InFlow\Services\Loading\Strategies\RelationSyncStrategyFactory;
use InFlow\Transforms\TransformEngine;
use InFlow\ValueObjects\Data\Row;

/**
 * Simplified Eloquent loader that consolidates all loading services.
 *
 * This class implements a "relation-driven" approach to ETL mapping, where relations
 * are specified using dot notation (e.g., "category.name") instead of foreign keys.
 */
class EloquentLoader
{
    /**
     * Details of truncated fields in current row
     *
     * @var array<int, array{field: string, original_length: int, max_length: int}>
     */
    private array $truncatedFields = [];

    /**
     * Cache for column max lengths per model
     *
     * @var array<string, array<string, int|null>>
     */
    private static array $columnMaxLengthsCache = [];

    public function __construct(
        private readonly TransformEngine $transformEngine,
        private readonly RelationTypeService $relationTypeService,
        private readonly RelationSyncStrategyFactory $strategyFactory
    ) {}

    /**
     * Get details of truncated fields in current row
     *
     * @return array<int, array{field: string, original_length: int, max_length: int}>
     */
    public function getTruncatedFields(): array
    {
        return $this->truncatedFields;
    }

    /**
     * Reset truncated fields for new row
     */
    public function resetTruncatedFields(): void
    {
        $this->truncatedFields = [];
    }

    /**
     * Load a row into a model using the mapping definition
     *
     * TODO: Re-implement with new mapping system
     *
     * @param  bool  $truncateLongFields  Whether to truncate fields that exceed column max length
     * @return Model|null The loaded model, or null if the model was skipped (e.g., duplicate with 'skip' strategy)
     */
    public function load(Row $row, mixed $mapping, bool $truncateLongFields = true): ?Model
    {
        // TODO: Re-implement with new mapping system
        return null;
    }

    // ========== Attribute Grouping (consolidated from AttributeGroupingService) ==========

    /**
     * Group column mappings into attributes and relations.
     *
     * TODO: Re-implement with new mapping system
     */
    // TODO: Re-implement groupAttributesAndRelations with new mapping system

    // TODO: Re-implement extractValue with new mapping system
    private function extractValue(Row $row, mixed $columnMapping): mixed
    {
        // Handle virtual source columns (for default values, generated values, etc.)
        if ($this->isVirtualColumn($columnMapping->sourceColumn)) {
            return $columnMapping->default;
        }

        $value = $row->get($columnMapping->sourceColumn);

        // Apply default if value is empty
        if ($value === null || $value === '') {
            $value = $columnMapping->default;
        }

        // Apply transformations
        return $this->transformEngine->apply(
            $value,
            $columnMapping->transforms,
            ['row' => $row->toArray()]
        );
    }

    private function isVirtualColumn(string $sourceColumn): bool
    {
        return str_starts_with($sourceColumn, '__default_')
            || str_starts_with($sourceColumn, '__skip_')
            || str_starts_with($sourceColumn, '__random_');
    }

    /**
     * Validate and truncate string value if it exceeds column max length.
     */
    private function validateAndTruncate(string $modelClass, string $attributeName, string $value): array
    {
        if ($value === '') {
            return ['value' => $value, 'truncated' => false, 'details' => null];
        }

        $maxLength = $this->getColumnMaxLength($modelClass, $attributeName);

        if ($maxLength === null || mb_strlen($value) <= $maxLength) {
            return ['value' => $value, 'truncated' => false, 'details' => null];
        }

        // Truncate value that exceeds max length
        $originalLength = mb_strlen($value);
        $truncatedValue = mb_substr($value, 0, $maxLength);

        return [
            'value' => $truncatedValue,
            'truncated' => true,
            'details' => [
                'field' => $attributeName,
                'original_length' => $originalLength,
                'max_length' => $maxLength,
            ],
        ];
    }

    /**
     * Get maximum length for a database column.
     */
    private function getColumnMaxLength(string $modelClass, string $columnName): ?int
    {
        $cacheKey = "{$modelClass}::{$columnName}";

        if (isset(self::$columnMaxLengthsCache[$cacheKey])) {
            return self::$columnMaxLengthsCache[$cacheKey];
        }

        try {
            $model = new $modelClass;
            $table = $model->getTable();

            $columns = DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$columnName]);

            if (empty($columns)) {
                self::$columnMaxLengthsCache[$cacheKey] = null;

                return null;
            }

            $column = $columns[0];
            $type = $column->Type;

            // Extract length from VARCHAR(255), CHAR(10), etc.
            if (preg_match('/^(varchar|char|varbinary|binary)\((\d+)\)/i', $type, $matches)) {
                $maxLength = (int) $matches[2];
                self::$columnMaxLengthsCache[$cacheKey] = $maxLength;

                return $maxLength;
            }

            // TEXT types
            if (stripos($type, 'tinytext') !== false) {
                self::$columnMaxLengthsCache[$cacheKey] = 255;

                return 255;
            }
            if (stripos($type, 'text') !== false && stripos($type, 'tiny') === false) {
                // TEXT, MEDIUMTEXT, LONGTEXT - no practical limit
                self::$columnMaxLengthsCache[$cacheKey] = null;

                return null;
            }

            // No length limit found
            self::$columnMaxLengthsCache[$cacheKey] = null;

            return null;
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', [
                'operation' => 'getColumnMaxLength',
                'model' => $modelClass,
                'column' => $columnName,
            ]);
            self::$columnMaxLengthsCache[$cacheKey] = null;

            return null;
        }
    }

    /**
     * Add value to relation data structure.
     */
    private function addToRelation(
        array &$relations,
        array $pathParts,
        mixed $transformedValue,
        ColumnMapping $columnMapping,
        ModelMapping $mapping
    ): void {
        $relationName = $pathParts[0];
        $this->ensureRelationExists($relations, $relationName);

        // Route to appropriate handler
        if ($pathParts[1] === '*') {
            $this->handleFullArrayMapping($relations, $relationName, $transformedValue, $columnMapping, $mapping);

            return;
        }

        if ($pathParts[1] === 'pivot' && isset($pathParts[2])) {
            $this->handlePivotMapping($relations, $relationName, $pathParts[2], $transformedValue);

            return;
        }

        $this->handleNormalRelationField($relations, $relationName, $pathParts[1], $transformedValue, $columnMapping, $mapping);
    }

    private function ensureRelationExists(array &$relations, string $relationName): void
    {
        if (! isset($relations[$relationName])) {
            $relations[$relationName] = [
                'data' => [],
                'lookup' => null,
                'pivot' => [],
                'field_transforms' => [],
            ];
        }
    }

    private function handleFullArrayMapping(
        array &$relations,
        string $relationName,
        mixed $transformedValue,
        ColumnMapping $columnMapping,
        ModelMapping $mapping
    ): void {
        if (! $this->isArrayRelation($mapping->modelClass, $relationName) || ! is_array($transformedValue)) {
            return;
        }

        $relations[$relationName]['data']['__array_data'] = $transformedValue;
        $relations[$relationName]['data']['__full_array'] = true;

        // Configure lookup automatically based on relation type
        $this->configureLookup(
            $relations[$relationName],
            $columnMapping,
            $relationName,
            '*',
            $mapping
        );
    }

    private function handlePivotMapping(
        array &$relations,
        string $relationName,
        string $pivotField,
        mixed $transformedValue
    ): void {
        [$field, $isOptional] = $this->parseOptionalField($pivotField);

        if ($transformedValue !== null || ! $isOptional) {
            $relations[$relationName]['pivot'][$field] = $transformedValue;
        }
    }

    private function handleNormalRelationField(
        array &$relations,
        string $relationName,
        string $relationAttribute,
        mixed $transformedValue,
        ColumnMapping $columnMapping,
        ModelMapping $mapping
    ): void {
        [$field, $isOptional] = $this->parseOptionalField($relationAttribute);

        $this->configureLookup($relations[$relationName], $columnMapping, $relationName, $field, $mapping);

        if ($transformedValue === null && $isOptional) {
            return;
        }

        if ($this->isArrayRelation($mapping->modelClass, $relationName) && is_array($transformedValue)) {
            $this->addArrayRelationValue($relations, $relationName, $field, $transformedValue);
        } else {
            $this->addNormalRelationValue($relations, $relationName, $field, $transformedValue, $columnMapping);
        }
    }

    private function addArrayRelationValue(
        array &$relations,
        string $relationName,
        string $field,
        array $transformedValue
    ): void {
        if (! isset($relations[$relationName]['data']['__array_data'])) {
            $relations[$relationName]['data']['__array_data'] = [];
            $relations[$relationName]['data']['__fields'] = [];
        }

        if (empty($relations[$relationName]['data']['__array_data'])) {
            $relations[$relationName]['data']['__array_data'] = $transformedValue;
        }

        if (! in_array($field, $relations[$relationName]['data']['__fields'], true)) {
            $relations[$relationName]['data']['__fields'][] = $field;
        }
    }

    private function addNormalRelationValue(
        array &$relations,
        string $relationName,
        string $field,
        mixed $transformedValue,
        ColumnMapping $columnMapping
    ): void {
        $relations[$relationName]['data'][$field] = $transformedValue;

        if (! empty($columnMapping->transforms)) {
            $relations[$relationName]['field_transforms'][$field] = $columnMapping->transforms;
        }
    }

    private function isArrayRelation(string $modelClass, string $relationName): bool
    {
        $relationType = $this->relationTypeService->getRelationType($modelClass, $relationName);

        return in_array($relationType, [EloquentRelationType::HasMany, EloquentRelationType::BelongsToMany], true);
    }

    private function parseOptionalField(string $field): array
    {
        $isOptional = str_starts_with($field, '?');

        return [$isOptional ? substr($field, 1) : $field, $isOptional];
    }

    /**
     * Configure relation lookup automatically based on relation type.
     */
    private function configureLookup(
        array &$relationInfo,
        ColumnMapping $columnMapping,
        string $relationName,
        string $relationAttribute,
        ModelMapping $mapping
    ): void {
        // Auto-detect based on relation type
        $relationType = $this->relationTypeService->getRelationType($mapping->modelClass, $relationName);

        if ($relationType === EloquentRelationType::BelongsTo) {
            $relationInfo['lookup'] = [
                'column' => $columnMapping,
                'field' => $relationAttribute,
                'create_if_missing' => false,
            ];

            return;
        }

        if ($relationType === EloquentRelationType::HasMany) {
            // Only set lookup if not already set (first unique field wins)
            if ($relationInfo['lookup'] !== null) {
                return;
            }

            // Check if field has unique constraint in related model
            if ($this->isUniqueFieldInRelatedModel($mapping->modelClass, $relationName, $relationAttribute)) {
                $relationInfo['lookup'] = [
                    'column' => $columnMapping,
                    'field' => $relationAttribute,
                    'create_if_missing' => true,
                ];
            }
        }
    }

    private function isUniqueFieldInRelatedModel(string $parentModelClass, string $relationName, string $fieldName): bool
    {
        try {
            if (! class_exists($parentModelClass)) {
                return false;
            }

            $parentModel = new $parentModelClass;

            if (! method_exists($parentModel, $relationName)) {
                return false;
            }

            $relation = $parentModel->$relationName();

            if (! method_exists($relation, 'getRelated')) {
                return false;
            }

            $relatedModel = $relation->getRelated();
            $table = $relatedModel->getTable();
            $primaryKey = $relatedModel->getKeyName();

            // Check if field is primary key
            if ($fieldName === $primaryKey) {
                return true;
            }

            // Check for unique indexes
            $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Column_name = ? AND Non_unique = 0", [$fieldName]);

            return ! empty($indexes);
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', [
                'operation' => 'isUniqueFieldInRelatedModel',
                'parent_model' => $parentModelClass,
                'relation' => $relationName,
                'field' => $fieldName,
            ]);

            return false;
        }
    }

    // ========== Model Persistence (consolidated from ModelPersistenceService) ==========

    /**
     * Create or update a model based on options.
     */
    private function createOrUpdate(string $modelClass, array $attributes, array $options): ?Model
    {
        $uniqueKey = $this->normalizeUniqueKey($options['unique_key'] ?? null);
        $duplicateStrategy = $this->parseDuplicateStrategy($options['duplicate_strategy'] ?? 'error');

        if ($uniqueKey === null) {
            return $this->createWithoutUniqueKey($modelClass, $attributes, $duplicateStrategy);
        }

        return $this->createWithUniqueKey($modelClass, $attributes, $uniqueKey, $duplicateStrategy);
    }

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

    private function handleDuplicateError(QueryException $e, string $modelClass, array $attributes, DuplicateStrategy $duplicateStrategy): ?Model
    {
        return match ($duplicateStrategy) {
            DuplicateStrategy::Skip => null,
            DuplicateStrategy::Update => $this->updateFromDuplicateError($e, $modelClass, $attributes),
            DuplicateStrategy::Error => throw $e,
        };
    }

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

    private function updateModel(Model $model, array $attributes): Model
    {
        $model->update($attributes);

        return $model;
    }

    private function parseDuplicateStrategy(string $strategy): DuplicateStrategy
    {
        return DuplicateStrategy::tryFrom($strategy) ?? DuplicateStrategy::Error;
    }

    private function isDuplicateKeyError(QueryException $e): bool
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        return $code === 1062
            || $code === 23000
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint')
            || str_contains($message, 'duplicate key');
    }

    private function extractDuplicateFieldFromError(QueryException $e): ?string
    {
        $message = $e->getMessage();

        if (preg_match("/for key '([^']+)'/", $message, $matches)) {
            $keyName = $matches[1];
            if (preg_match('/_([^_]+)_unique$/', $keyName, $fieldMatches)) {
                return $fieldMatches[1];
            }
            if (preg_match('/(?:\.|_)([^_.]+)_unique$/', $keyName, $fieldMatches)) {
                return $fieldMatches[1];
            }
        }

        return null;
    }

    // ========== Relation Resolution (consolidated from RelationResolutionService) ==========

    /**
     * Separate relations into BelongsTo and other types.
     */
    private function separateRelations(array $relations, string $modelClass): array
    {
        $belongsToRelations = [];
        $otherRelations = [];

        foreach ($relations as $relationName => $relationInfo) {
            $relationData = $relationInfo['data'] ?? $relationInfo;
            $lookup = $relationInfo['lookup'] ?? null;
            $pivot = $relationInfo['pivot'] ?? [];

            // Check if this is a BelongsTo relation with lookup
            if ($lookup !== null) {
                $relationType = $this->relationTypeService->getRelationType($modelClass, $relationName);

                if ($relationType === EloquentRelationType::BelongsTo) {
                    // Resolve BelongsTo relation and add foreign key to attributes
                    $foreignKey = $this->resolveBelongsToRelation(
                        $modelClass,
                        $relationName,
                        $lookup,
                        $relationData
                    );

                    if ($foreignKey !== null) {
                        $belongsToRelations[$relationName] = [
                            'data' => $relationData,
                            'lookup' => $lookup,
                            'related_id' => $foreignKey['value'],
                            'foreign_key' => $foreignKey,
                        ];
                    }

                    continue;
                }
            }

            // Other relations (HasOne, HasMany, etc.) - sync after model is created
            $otherRelations[$relationName] = [
                'data' => $relationData,
                'lookup' => $lookup,
                'pivot' => $pivot,
            ];
        }

        return [
            'belongsTo' => $belongsToRelations,
            'other' => $otherRelations,
        ];
    }

    /**
     * Resolve BelongsTo relation and return foreign key value.
     */
    private function resolveBelongsToRelation(
        string $modelClass,
        string $relationName,
        array $lookup,
        array $relationData
    ): ?array {
        $lookupField = $lookup['field'];
        $createIfMissing = $lookup['create_if_missing'] ?? false;

        // Get the lookup value from relationData (e.g., category name)
        $lookupValue = $relationData[$lookupField] ?? null;

        if ($lookupValue === null || $lookupValue === '') {
            return null; // No value to lookup
        }

        // Get the related model class and relation instance
        try {
            $tempModel = new $modelClass;
            if (! method_exists($tempModel, $relationName)) {
                return null;
            }

            $relation = $tempModel->$relationName();
            $relatedModelClass = get_class($relation->getRelated());

            // Find the related model by the lookup field
            $relatedModel = $relatedModelClass::where($lookupField, $lookupValue)->first();

            if ($relatedModel === null) {
                if ($createIfMissing) {
                    // Create the related model if it doesn't exist
                    $createData = [$lookupField => $lookupValue];
                    $createData = array_merge($createData, array_diff_key($relationData, [$lookupField => true]));
                    $relatedModel = $relatedModelClass::firstOrCreate(
                        [$lookupField => $lookupValue],
                        $createData
                    );
                } else {
                    // Related model not found and we shouldn't create it
                    return null;
                }
            }

            // Get the foreign key name and value
            $foreignKey = $relation->getForeignKeyName();
            $foreignKeyValue = $relatedModel->getKey();

            return [
                'key' => $foreignKey,
                'value' => $foreignKeyValue,
            ];
        } catch (\Exception $e) {
            throw RelationResolutionException::fromDatabaseError(
                $e,
                $modelClass,
                $relationName,
                $lookupField,
                $lookupValue ?? 'null',
                $createIfMissing
            );
        }
    }

    /**
     * Set BelongsTo relations on model for immediate access.
     */
    private function setBelongsToRelations(Model $model, array $belongsToRelations): void
    {
        foreach ($belongsToRelations as $relationName => $relationInfo) {
            try {
                $relatedModelClass = get_class($model->$relationName()->getRelated());
                $relatedModel = $relatedModelClass::find($relationInfo['related_id']);
                if ($relatedModel !== null) {
                    $model->setRelation($relationName, $relatedModel);
                }
            } catch (\Exception $e) {
                \inflow_report($e, 'debug', [
                    'operation' => 'setRelation',
                    'relation' => $relationName,
                ]);
            }
        }
    }

    // ========== Relation Sync (consolidated from RelationSyncService) ==========

    /**
     * Sync a relation for a model.
     */
    private function syncRelation(Model $model, string $relationName, array $relationData, array $mappingOptions, ?array $lookup = null): void
    {
        if (! method_exists($model, $relationName)) {
            // Relation method doesn't exist - skip
            return;
        }

        $relation = $model->$relationName();
        $relationType = $this->relationTypeService->getRelationType(get_class($model), $relationName);

        if ($relationType === null) {
            // Unknown relation type - try to create
            $relation->create($relationData);

            return;
        }

        // Get appropriate strategy for relation type
        $strategy = $this->strategyFactory->create($relationType);

        // Delegate to strategy
        $strategy->sync($model, $relationName, $relation, $relationData, $mappingOptions, $lookup);
    }
}
