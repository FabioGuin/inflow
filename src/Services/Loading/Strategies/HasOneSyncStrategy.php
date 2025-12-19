<?php

namespace InFlow\Services\Loading\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Strategy for synchronizing HasOne relations.
 *
 * Handles lookup by unique attribute and ensures the related FK points to parent.
 */
class HasOneSyncStrategy extends AbstractRelationSyncStrategy implements RelationSyncStrategy
{
    public function sync(
        Model $model,
        string $relationName,
        Relation $relation,
        array $relationData,
        array $mappingOptions,
        ?array $lookup = null
    ): void {
        if ($lookup === null) {
            // No lookup configured - use default Eloquent behavior
            $this->syncSingleRelation($relation, $relationData);

            return;
        }

        $this->syncWithLookup($model, $relationName, $relation, $lookup, $relationData);
    }

    /**
     * Sync single relation (HasOne without lookup).
     */
    private function syncSingleRelation(Relation $relation, array $relationData): void
    {
        if ($this->isEmptyRelationData($relationData)) {
            return;
        }

        $existing = $relation->first();

        if ($existing !== null) {
            $existing->update($relationData);
        } else {
            $relation->create($relationData);
        }
    }

    /**
     * Sync HasOne relation using lookup (e.g., find by unique attribute instead of relying on foreign key).
     */
    private function syncWithLookup(
        Model $model,
        string $relationName,
        Relation $relation,
        array $lookup,
        array $relationData
    ): void {
        $lookupField = $lookup['field'];
        $createIfMissing = $lookup['create_if_missing'] ?? false;

        $lookupValue = $relationData[$lookupField] ?? null;
        if ($lookupValue === null || $lookupValue === '') {
            return;
        }

        // If this isn't a HasOne relation instance, fall back to normal behavior
        if (! $relation instanceof HasOne) {
            $this->syncSingleRelation($relation, $relationData);

            return;
        }

        $relatedModelClass = get_class($relation->getRelated());
        $relatedModel = $relatedModelClass::where($lookupField, $lookupValue)->first();

        if ($relatedModel === null) {
            if (! $createIfMissing) {
                return;
            }

            $created = $relation->create($relationData);
            $model->setRelation($relationName, $created);

            return;
        }

        $foreignKey = $relation->getForeignKeyName();
        $localKey = $relation->getLocalKeyName();

        $relatedModel->fill($relationData);
        $relatedModel->$foreignKey = $model->getAttribute($localKey);
        $relatedModel->save();

        $model->setRelation($relationName, $relatedModel);
    }
}

