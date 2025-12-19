<?php

namespace InFlow\Services\Loading\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Strategy for synchronizing BelongsToMany relations.
 *
 * Handles:
 * - Pivot data
 * - Delimited string values (e.g., "tag1,tag2,tag3")
 * - Array data (multiple related items)
 * - Single item data
 * - Sync strategies (sync, attach, detach, syncWithoutDetaching)
 */
class BelongsToManySyncStrategy extends AbstractRelationSyncStrategy implements RelationSyncStrategy
{
    public function sync(
        Model $model,
        string $relationName,
        Relation $relation,
        array $relationData,
        array $mappingOptions,
        ?array $lookup = null
    ): void {
        if (! $relation instanceof BelongsToMany) {
            $relation->create($relationData);

            return;
        }

        // Extract pivot data if present
        $pivotData = [];
        if (isset($relationData['__pivot']) && is_array($relationData['__pivot'])) {
            $pivotData = $relationData['__pivot'];
            unset($relationData['__pivot']);
        }

        $strategy = $mappingOptions['belongs_to_many_strategy'] ?? 'sync';

        // Build sync data (array of [id => pivot_data])
        if (isset($relationData['__array_data']) && is_array($relationData['__array_data'])) {
            $syncData = $this->buildSyncDataFromArray($relation, $relationData, $lookup, $pivotData);
        } else {
            $syncData = $this->buildSyncDataFromSingle($relation, $relationData, $lookup, $pivotData);
        }

        if (empty($syncData) && $strategy !== 'detach') {
            return;
        }

        // Apply sync strategy
        match ($strategy) {
            'attach', 'sync_without_detaching' => $relation->syncWithoutDetaching($syncData),
            'detach' => $this->detach($relation, $syncData),
            default => $relation->sync($syncData),
        };
    }

    /**
     * Build sync data from single item.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function buildSyncDataFromSingle(
        BelongsToMany $relation,
        array $relationData,
        ?array $lookup,
        array $pivotData
    ): array {
        // Prefer explicit id
        if (isset($relationData['id'])) {
            return [$relationData['id'] => $pivotData];
        }

        if ($lookup === null) {
            return [];
        }

        $lookupField = $lookup['field'];
        $createIfMissing = $lookup['create_if_missing'] ?? false;
        $lookupValue = $relationData[$lookupField] ?? null;

        if ($lookupValue === null || $lookupValue === '') {
            return [];
        }

        // Handle multi-value strings with configurable delimiter (e.g., "classic,romance,fiction")
        $delimiter = $lookup['delimiter'] ?? null;
        if ($delimiter !== null && is_string($lookupValue) && str_contains($lookupValue, $delimiter)) {
            // Extract field transforms for per-value application
            $fieldTransforms = $relationData['__field_transforms'] ?? [];
            unset($relationData['__field_transforms']);

            // Pass additional fields (excluding lookup field and metadata) for create
            $additionalFields = array_diff_key($relationData, [$lookupField => true, '__pivot' => true]);

            return $this->buildSyncDataFromDelimitedString(
                $relation,
                $lookupValue,
                $lookupField,
                $createIfMissing,
                $pivotData,
                $delimiter,
                $additionalFields,
                $fieldTransforms
            );
        }

        // Single value lookup
        $relatedClass = get_class($relation->getRelated());
        $related = $relatedClass::where($lookupField, $lookupValue)->first();

        if ($related === null && $createIfMissing) {
            $createData = [$lookupField => $lookupValue];
            $createData = array_merge($createData, array_diff_key($relationData, [$lookupField => true]));
            $related = $relatedClass::firstOrCreate([$lookupField => $lookupValue], $createData);
        }

        if ($related === null) {
            return [];
        }

        return [$related->getKey() => $pivotData];
    }

    /**
     * Build sync data from delimited string values.
     *
     * @param  array<string, mixed>  $additionalFields  Additional fields to include when creating
     * @param  array<string, array<string>>  $fieldTransforms  Transforms to apply per field (field => transforms)
     * @return array<int|string, array<string, mixed>>
     */
    private function buildSyncDataFromDelimitedString(
        BelongsToMany $relation,
        string $delimitedValues,
        string $lookupField,
        bool $createIfMissing,
        array $pivotData,
        string $delimiter,
        array $additionalFields = [],
        array $fieldTransforms = []
    ): array {
        $values = array_map('trim', explode($delimiter, $delimitedValues));
        $values = array_filter($values, fn ($itemValue) => $itemValue !== '');

        if (empty($values)) {
            return [];
        }

        $relatedClass = get_class($relation->getRelated());
        $syncData = [];

        foreach ($values as $value) {
            $related = $relatedClass::where($lookupField, $value)->first();

            if ($related === null && $createIfMissing) {
                // Build create data with transformed fields
                $createData = [$lookupField => $value];

                foreach ($additionalFields as $fieldName => $fieldValue) {
                    // Apply transforms if defined for this field
                    $transforms = $fieldTransforms[$fieldName] ?? [];
                    $createData[$fieldName] = $this->applyTransformsToValue($fieldValue, $transforms);
                }

                $related = $relatedClass::firstOrCreate([$lookupField => $value], $createData);
            }

            if ($related !== null) {
                $syncData[$related->getKey()] = $pivotData;
            }
        }

        return $syncData;
    }

    /**
     * Build sync data from array of items.
     *
     * @param  array{column: \InFlow\ValueObjects\ColumnMapping, field: string, create_if_missing: bool, delimiter?: string|null}|null  $lookup
     * @return array<int|string, array<string, mixed>>
     */
    private function buildSyncDataFromArray(
        BelongsToMany $relation,
        array $relationData,
        ?array $lookup,
        array $pivotData
    ): array {
        $arrayData = $relationData['__array_data'];
        $fields = $relationData['__fields'] ?? [];
        $isFullArray = $relationData['__full_array'] ?? false;

        $items = [];
        $lookupValues = [];
        $ids = [];

        foreach ($arrayData as $relationItem) {
            if (! is_array($relationItem) && ! is_object($relationItem)) {
                continue;
            }

            $relationItemArray = is_object($relationItem) ? (array) $relationItem : $relationItem;

            // Prefer explicit id
            if (isset($relationItemArray['id'])) {
                $ids[] = $relationItemArray['id'];

                continue;
            }

            if ($lookup === null) {
                continue;
            }

            $lookupField = $lookup['field'];
            $lookupValue = $this->findFieldValueInRelationItem($relationItemArray, $lookupField);
            if ($lookupValue === null || $lookupValue === '') {
                continue;
            }

            // If full array, use all fields; otherwise extract only specified fields
            if ($isFullArray) {
                $itemData = $relationItemArray;
            } else {
                $itemData = $this->extractFieldsFromRelationItem($relationItemArray, $fields);
            }

            if (empty($itemData)) {
                continue;
            }

            $items[] = [
                'lookup' => $lookupValue,
                'data' => $itemData,
            ];
            $lookupValues[] = $lookupValue;
        }

        $syncData = [];

        // Add items with explicit IDs
        foreach (array_unique($ids) as $id) {
            $syncData[$id] = $pivotData;
        }

        if ($lookup === null || empty($items)) {
            return $syncData;
        }

        // Batch optimization: pre-load existing items
        $lookupField = $lookup['field'];
        $createIfMissing = $lookup['create_if_missing'] ?? false;
        $relatedClass = get_class($relation->getRelated());

        $existingByLookup = $relatedClass::whereIn($lookupField, array_values(array_unique($lookupValues)))
            ->get()
            ->keyBy($lookupField);

        foreach ($items as $relationItem) {
            $lookupValue = $relationItem['lookup'];
            $itemData = $relationItem['data'];

            $related = $existingByLookup->get($lookupValue);

            if ($related === null && $createIfMissing) {
                $createData = [$lookupField => $lookupValue];
                $createData = array_merge($createData, array_diff_key($itemData, [$lookupField => true]));
                $related = $relatedClass::firstOrCreate([$lookupField => $lookupValue], $createData);
            }

            if ($related !== null) {
                $syncData[$related->getKey()] = $pivotData;
            }
        }

        return $syncData;
    }

    /**
     * Detach relations.
     *
     * @param  array<int|string, array<string, mixed>>  $syncData
     */
    private function detach(BelongsToMany $relation, array $syncData): void
    {
        if (empty($syncData)) {
            $relation->detach();

            return;
        }

        $relation->detach(array_keys($syncData));
    }
}

