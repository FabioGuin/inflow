<?php

namespace InFlow\Services\Loading;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InFlow\Enums\EloquentRelationType;
use InFlow\Services\Loading\Strategies\RelationSyncStrategyFactory;

/**
 * Service for synchronizing Eloquent relations.
 *
 * Handles business logic for:
 * - Synchronizing different relation types (HasOne, HasMany, BelongsToMany)
 * - Handling relation lookups
 * - Processing array data for HasMany relations
 *
 * Uses Strategy Pattern to delegate relation-specific logic to dedicated strategy classes.
 *
 * Presentation logic (logging, errors) is handled by the caller.
 */
readonly class RelationSyncService
{
    public function __construct(
        private RelationTypeService $relationTypeService,
        private RelationSyncStrategyFactory $strategyFactory
    ) {}

    /**
     * Sync a relation for a model.
     *
     * Business logic: synchronizes relation based on type using Strategy Pattern.
     *
     * @param  Model  $model  The parent model
     * @param  string  $relationName  The relation name
     * @param  array<string, mixed>  $relationData  The relation data
     * @param  array<string, mixed>  $mappingOptions  The mapping options
     * @param  array{column: \InFlow\ValueObjects\ColumnMapping, field: string, create_if_missing: bool, delimiter?: string|null}|null  $lookup  Lookup configuration
     */
    public function syncRelation(Model $model, string $relationName, array $relationData, array $mappingOptions, ?array $lookup = null): void
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
