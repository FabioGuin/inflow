<?php

namespace InFlow\Services\Loading\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Strategy interface for synchronizing Eloquent relations.
 *
 * Each relation type (BelongsTo, HasOne, HasMany, BelongsToMany) has its own
 * strategy implementation that handles the specific synchronization logic.
 */
interface RelationSyncStrategy
{
    /**
     * Sync a relation for a model.
     *
     * @param  Model  $model  The parent model
     * @param  string  $relationName  The relation name (e.g., 'author', 'tags')
     * @param  Relation  $relation  The relation instance
     * @param  array<string, mixed>  $relationData  The relation data
     * @param  array<string, mixed>  $mappingOptions  The mapping options (duplicate_strategy, etc.)
     * @param  array{column: \InFlow\ValueObjects\ColumnMapping, field: string, create_if_missing: bool, delimiter?: string|null}|null  $lookup  Lookup configuration
     */
    public function sync(
        Model $model,
        string $relationName,
        Relation $relation,
        array $relationData,
        array $mappingOptions,
        ?array $lookup = null
    ): void;
}
