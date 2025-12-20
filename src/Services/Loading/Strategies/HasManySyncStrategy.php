<?php

namespace InFlow\Services\Loading\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use InFlow\Services\Loading\ModelPersistenceService;
use InFlow\Transforms\TransformEngine;

/**
 * Strategy for synchronizing HasMany relations.
 *
 * Handles:
 * - Single relation data
 * - Array data (multiple children)
 * - Lookup by unique attribute
 * - Batch optimization for array data
 */
class HasManySyncStrategy extends AbstractRelationSyncStrategy implements RelationSyncStrategy
{
    public function __construct(
        TransformEngine $transformEngine,
        private ModelPersistenceService $modelPersistenceService
    ) {
        parent::__construct($transformEngine);
    }

    public function sync(
        Model $model,
        string $relationName,
        Relation $relation,
        array $relationData,
        array $mappingOptions,
        ?array $lookup = null
    ): void {
        if ($lookup === null) {
            // No lookup configured - use default behavior
            $this->syncHasManyRelation($relation, $relationData);

            return;
        }

        $this->syncWithLookup($relation, $lookup, $relationData, $mappingOptions);
    }

    /**
     * Sync HasMany relation without lookup.
     */
    private function syncHasManyRelation(Relation $relation, array $relationData): void
    {
        // Check if we have array data
        if (isset($relationData['__array_data']) && is_array($relationData['__array_data'])) {
            $this->syncHasManyArrayData($relation, $relationData);

            return;
        }

        // Normal case: single relation data
        if ($this->isEmptyRelationData($relationData)) {
            return;
        }

        $relation->create($relationData);
    }

    /**
     * Sync HasMany relation with array data (no lookup).
     */
    private function syncHasManyArrayData(Relation $relation, array $relationData): void
    {
        $arrayData = $relationData['__array_data'];
        $fields = $relationData['__fields'] ?? [];
        $isFullArray = $relationData['__full_array'] ?? false;

        // Process each element in the array
        foreach ($arrayData as $relationItem) {
            if (! is_array($relationItem) && ! is_object($relationItem)) {
                continue;
            }

            // Convert object to array if needed
            $relationItemArray = is_object($relationItem) ? (array) $relationItem : $relationItem;

            // Build relation data from array item
            if ($isFullArray) {
                // Full array mapping: use all fields from source
                $itemRelationData = $relationItemArray;
            } else {
                // Selective mapping: only use specified fields
                $itemRelationData = $this->extractFieldsFromRelationItem($relationItemArray, $fields);
            }

            // Only create if we have at least one field
            if (! empty($itemRelationData)) {
                $relation->create($itemRelationData);
            }
        }
    }

    /**
     * Sync HasMany relation using lookup.
     */
    private function syncWithLookup(Relation $relation, array $lookup, array $relationData, array $mappingOptions): void
    {
        $lookupField = $lookup['field'];
        $createIfMissing = $lookup['create_if_missing'] ?? false;

        if (! $relation instanceof HasMany) {
            $this->syncHasManyRelation($relation, $relationData);

            return;
        }

        if (isset($relationData['__array_data']) && is_array($relationData['__array_data'])) {
            $this->syncHasManyArrayDataWithLookup($relation, $lookupField, $createIfMissing, $relationData, $mappingOptions);

            return;
        }

        // Single item with lookup
        $lookupValue = $relationData[$lookupField] ?? null;
        if ($lookupValue === null || $lookupValue === '') {
            return;
        }

        // Ensure foreign key is set for HasMany relation
        $foreignKey = $relation->getForeignKeyName();
        $localKey = $relation->getLocalKeyName();
        $parentModel = $relation->getParent();
        $relationData[$foreignKey] = $parentModel->getAttribute($localKey);

        // Use ModelPersistenceService to respect duplicate_strategy
        $relatedModelClass = get_class($relation->getRelated());

        // Build relation-specific options: use lookup field as unique_key, inherit duplicate_strategy
        $relationOptions = [
            'unique_key' => $lookupField,
            'duplicate_strategy' => $mappingOptions['duplicate_strategy'] ?? 'error',
        ];

        $this->modelPersistenceService->createOrUpdate($relatedModelClass, $relationData, $relationOptions);
    }

    /**
     * Sync HasMany relation with array data and lookup (batch optimized).
     */
    private function syncHasManyArrayDataWithLookup(
        HasMany $relation,
        string $lookupField,
        bool $createIfMissing,
        array $relationData,
        array $mappingOptions
    ): void {
        $arrayData = $relationData['__array_data'];
        $fields = $relationData['__fields'] ?? [];
        $isFullArray = $relationData['__full_array'] ?? false;

        $items = [];
        $lookupValues = [];

        foreach ($arrayData as $relationItem) {
            if (! is_array($relationItem) && ! is_object($relationItem)) {
                continue;
            }

            $relationItemArray = is_object($relationItem) ? (array) $relationItem : $relationItem;
            $lookupValue = $this->findFieldValueInRelationItem($relationItemArray, $lookupField);
            if ($lookupValue === null || $lookupValue === '') {
                continue;
            }

            // Use all fields from source when __full_array is true, otherwise extract specific fields
            $itemData = $isFullArray
                ? $relationItemArray
                : $this->extractFieldsFromRelationItem($relationItemArray, $fields);
            if (empty($itemData)) {
                continue;
            }

            $items[] = [
                'lookup' => $lookupValue,
                'data' => $itemData,
            ];
            $lookupValues[] = $lookupValue;
        }

        if (empty($items)) {
            return;
        }

        // Batch optimization: pre-load existing children with a single whereIn query
        $existingByLookup = $relation
            ->whereIn($lookupField, array_values(array_unique($lookupValues)))
            ->get()
            ->keyBy($lookupField);

        // Ensure foreign key is set for HasMany relation
        $foreignKey = $relation->getForeignKeyName();
        $localKey = $relation->getLocalKeyName();
        $parentModel = $relation->getParent();
        $parentId = $parentModel->getAttribute($localKey);

        foreach ($items as $relationItem) {
            $lookupValue = $relationItem['lookup'];
            $itemData = $relationItem['data'];

            // Set foreign key for relation
            $itemData[$foreignKey] = $parentId;

            // Use ModelPersistenceService to respect duplicate_strategy
            $relatedModelClass = get_class($relation->getRelated());

            // Build relation-specific options: use lookup field as unique_key, inherit duplicate_strategy
            $relationOptions = [
                'unique_key' => $lookupField,
                'duplicate_strategy' => $mappingOptions['duplicate_strategy'] ?? 'error',
            ];

            // ModelPersistenceService will handle create/update based on duplicate_strategy
            // It checks for existing record by unique_key and applies the strategy
            if ($createIfMissing || $existingByLookup->has($lookupValue)) {
                $this->modelPersistenceService->createOrUpdate($relatedModelClass, $itemData, $relationOptions);
            }
        }
    }
}
