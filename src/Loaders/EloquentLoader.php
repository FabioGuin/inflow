<?php

namespace InFlow\Loaders;

use Illuminate\Database\Eloquent\Model;
use InFlow\Services\Loading\AttributeGroupingService;
use InFlow\Services\Loading\ModelPersistenceService;
use InFlow\Services\Loading\RelationResolutionService;
use InFlow\Services\Loading\RelationSyncService;
use InFlow\ValueObjects\ModelMapping;
use InFlow\ValueObjects\Row;

/**
 * Loader for applying mappings to Eloquent models with nested relation support
 *
 * This class implements a "relation-driven" approach to ETL mapping, where relations
 * are specified using dot notation (e.g., "category.name") instead of foreign keys.
 *
 * **Why Relation-Driven?**
 * - Aligned with real ETL scenarios (files contain names/attributes, not IDs)
 * - Leverages existing Eloquent relations (DRY principle)
 * - Intuitive syntax for business users
 * - Automatic lookup and "create if missing" support
 * - Scalable to nested relations (e.g., "address.city.country.name")
 *
 * **Performance Considerations:**
 * - Extra queries for lookups (acceptable for batch ETL)
 * - Future: batch lookup optimization (group by value)
 * - Future: caching for frequent lookups
 *
 * **Alternative Approaches Considered:**
 * - ID-Driven: Simple but requires pre-processing (not suitable for ETL)
 * - Attribute-Driven: More explicit but less intuitive
 * - Hybrid: Best of both worlds (future enhancement)
 *
 * @todo [RELATIONS] Comprehensive relation support roadmap:
 *   ✅ BelongsTo with lookup (find by attribute, create if missing)
 *   ✅ HasOne with lookup support
 *   ✅ HasMany with lookup, update/create logic, batch operations
 *   ✅ BelongsToMany with pivot data, attach/detach/sync
 *   ⏳ MorphTo/MorphOne/MorphMany (polymorphic relations)
 *   ⏳ Hybrid support (ID direct when available, relation-driven otherwise)
 *   ⏳ Batch lookup optimization (group queries by value)
 *   ⏳ Lookup caching for performance
 *
 * Relations are a critical part of ETL workflows and need careful implementation
 * to handle various scenarios: lookup by attributes, create-if-missing, update-existing,
 * batch operations, and pivot table data.
 *
 * @see docs/relation-driven-analysis.md for detailed analysis
 */
class EloquentLoader
{
    /**
     * Details of truncated fields in current row
     *
     * @var array<int, array{field: string, original_length: int, max_length: int}>
     */
    private array $truncatedFields = [];

    public function __construct(
        private readonly AttributeGroupingService $attributeGroupingService,
        private readonly ModelPersistenceService $modelPersistenceService,
        private readonly RelationResolutionService $relationResolutionService,
        private readonly RelationSyncService $relationSyncService
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
     * Business logic: orchestrates the loading process using dedicated services.
     * Presentation logic (logging, errors) is handled by services.
     *
     * @return Model|null The loaded model, or null if the model was skipped (e.g., duplicate with 'skip' strategy)
     */
    public function load(Row $row, ModelMapping $mapping): ?Model
    {
        // Business logic: group attributes and relations
        $grouped = $this->attributeGroupingService->groupAttributesAndRelations($row, $mapping);
        $attributes = $grouped['attributes'];
        $relations = $grouped['relations'];
        $this->truncatedFields = $grouped['truncatedFields'];

        // Business logic: separate BelongsTo relations from others
        // BelongsTo relations need to be resolved FIRST to get foreign keys before creating/updating the model
        $separated = $this->relationResolutionService->separateRelations($relations, $mapping->modelClass);
        $belongsToRelations = $separated['belongsTo'];
        $otherRelations = $separated['other'];

        // Business logic: add foreign keys from BelongsTo relations to attributes
        foreach ($belongsToRelations as $relationInfo) {
            if (isset($relationInfo['foreign_key'])) {
                $attributes[$relationInfo['foreign_key']['key']] = $relationInfo['foreign_key']['value'];
            }
        }

        // Business logic: create/update main model (now with foreign keys from BelongsTo relations)
        $model = $this->modelPersistenceService->createOrUpdate($mapping->modelClass, $attributes, $mapping->options);

        // If model is null (skipped duplicate), return null
        if ($model === null) {
            return null;
        }

        // Business logic: sync other relations (HasOne, HasMany, BelongsToMany, etc.)
        foreach ($otherRelations as $relationName => $relationInfo) {
            if (! empty($relationInfo['pivot'])) {
                $relationInfo['data']['__pivot'] = $relationInfo['pivot'];
            }

            // Include field transforms for per-value application
            if (! empty($relationInfo['field_transforms'])) {
                $relationInfo['data']['__field_transforms'] = $relationInfo['field_transforms'];
            }

            $this->relationSyncService->syncRelation(
                $model,
                $relationName,
                $relationInfo['data'],
                $mapping->options,
                $relationInfo['lookup']
            );
        }

        // Business logic: set BelongsTo relations on model for immediate access
        $this->relationResolutionService->setBelongsToRelations($model, $belongsToRelations);

        return $model;
    }
}
