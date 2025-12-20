<?php

namespace InFlow\Services\Loading\Strategies;

use InFlow\Enums\Data\EloquentRelationType;
use InFlow\Services\Loading\ModelPersistenceService;
use InFlow\Transforms\TransformEngine;

/**
 * Factory for creating relation sync strategies.
 *
 * Returns the appropriate strategy based on relation type.
 */
readonly class RelationSyncStrategyFactory
{
    public function __construct(
        private TransformEngine $transformEngine,
        private ModelPersistenceService $modelPersistenceService
    ) {}

    /**
     * Create strategy for relation type.
     */
    public function create(EloquentRelationType $relationType): RelationSyncStrategy
    {
        return match ($relationType) {
            EloquentRelationType::BelongsTo => new BelongsToSyncStrategy($this->transformEngine),
            EloquentRelationType::HasOne => new HasOneSyncStrategy($this->transformEngine),
            EloquentRelationType::HasMany => new HasManySyncStrategy($this->transformEngine, $this->modelPersistenceService),
            EloquentRelationType::BelongsToMany => new BelongsToManySyncStrategy($this->transformEngine),
            default => throw new \InvalidArgumentException("Unsupported relation type: {$relationType->value}"),
        };
    }
}
