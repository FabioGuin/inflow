<?php

namespace InFlow\Services\Loading;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InFlow\Enums\EloquentRelationType;
use InFlow\Exceptions\RelationResolutionException;
use InFlow\ValueObjects\ColumnMapping;

/**
 * Service for resolving and synchronizing Eloquent relations.
 *
 * Handles business logic for:
 * - Resolving BelongsTo relations (lookup by attribute, create if missing)
 * - Synchronizing different relation types (HasOne, HasMany, BelongsToMany)
 * - Handling relation lookups and foreign keys
 *
 * Presentation logic (logging, errors) is handled by the caller.
 */
readonly class RelationResolutionService
{
    public function __construct(
        private RelationTypeService $relationTypeService
    ) {}

    /**
     * Resolve BelongsTo relation and return foreign key value.
     *
     * Business logic: finds or creates related model, returns foreign key.
     *
     * @param  string  $modelClass  The parent model class
     * @param  string  $relationName  Name of the relation method (e.g., 'category')
     * @param  array{column: ColumnMapping, field: string, create_if_missing: bool}  $lookup  Lookup configuration
     * @param  array<string, mixed>  $relationData  Relation data containing lookup value
     * @return array{key: string, value: int|string}|null Returns foreign key name and value, or null if not found
     */
    public function resolveBelongsToRelation(
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
                    // Create the related model if it doesn't exist (use firstOrCreate to handle duplicates)
                    $createData = [$lookupField => $lookupValue];
                    // Merge any additional relation data (excluding the lookup field)
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
            // Throw a detailed exception for interactive handling
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
     * Separate relations into BelongsTo and other types.
     *
     * Business logic: groups relations by type for processing order.
     *
     * @param  array<string, array{data: array, lookup: array|null, pivot?: array<string, mixed>}>  $relations  The relations to separate
     * @param  string  $modelClass  The model class
     * @return array{belongsTo: array<string, array{data: array, lookup: array, related_id: int|string}>, other: array<string, array{data: array, lookup: array|null, pivot: array<string, mixed>}>} Separated relations
     */
    public function separateRelations(array $relations, string $modelClass): array
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
     * Set BelongsTo relations on model for immediate access.
     *
     * Business logic: loads and sets related models on the parent model.
     *
     * @param  Model  $model  The parent model
     * @param  array<string, array{data: array, lookup: array, related_id: int|string}>  $belongsToRelations  The BelongsTo relations
     */
    public function setBelongsToRelations(Model $model, array $belongsToRelations): void
    {
        foreach ($belongsToRelations as $relationName => $relationInfo) {
            try {
                $relatedModelClass = get_class($model->$relationName()->getRelated());
                $relatedModel = $relatedModelClass::find($relationInfo['related_id']);
                if ($relatedModel !== null) {
                    $model->setRelation($relationName, $relatedModel);
                }
            } catch (\Exception $e) {
                // Ignore if relation can't be set
                \inflow_report($e, 'debug', [
                    'operation' => 'setRelation',
                    'relation' => $relationName,
                ]);
            }
        }
    }
}
