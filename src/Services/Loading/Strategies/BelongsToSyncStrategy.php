<?php

namespace InFlow\Services\Loading\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Strategy for synchronizing BelongsTo relations.
 *
 * Handles lookup by attribute (e.g., find Category by name instead of ID)
 * and creates related model if missing when create_if_missing is true.
 */
class BelongsToSyncStrategy extends AbstractRelationSyncStrategy implements RelationSyncStrategy
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
            // No lookup configured - BelongsTo requires lookup or explicit ID
            // If relationData contains an ID, we can use it directly
            if (isset($relationData['id'])) {
                $foreignKey = $relation->getForeignKeyName();
                $model->$foreignKey = $relationData['id'];
                $model->save();
            }

            return;
        }

        $this->syncWithLookup($model, $relationName, $relation, $lookup, $relationData);
    }

    /**
     * Sync BelongsTo relation using lookup (e.g., find by name instead of ID).
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

        // Get the lookup value from relationData (e.g., category name)
        $lookupValue = $relationData[$lookupField] ?? null;

        if ($lookupValue === null || $lookupValue === '') {
            return; // No value to lookup
        }

        // Get the related model class
        $relatedModelClass = get_class($relation->getRelated());

        // Find the related model by the lookup field
        $relatedModel = $relatedModelClass::where($lookupField, $lookupValue)->first();

        if ($relatedModel === null) {
            if ($createIfMissing) {
                // Create the related model if it doesn't exist
                $createData = [$lookupField => $lookupValue];
                // Merge any additional relation data (excluding the lookup field)
                $createData = array_merge($createData, array_diff_key($relationData, [$lookupField => true]));
                $relatedModel = $relatedModelClass::firstOrCreate(
                    [$lookupField => $lookupValue],
                    $createData
                );
            } else {
                // Related model not found and we shouldn't create it
                return;
            }
        }

        // Associate the related model to the parent model
        // For BelongsTo, we need to set the foreign key on the parent model
        $foreignKey = $relation->getForeignKeyName();
        $model->$foreignKey = $relatedModel->getKey();
        $model->save();

        // Set the relation on the model for immediate access
        $model->setRelation($relationName, $relatedModel);
    }
}
