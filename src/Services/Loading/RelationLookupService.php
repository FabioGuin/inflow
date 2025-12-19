<?php

namespace InFlow\Services\Loading;

use InFlow\Enums\EloquentRelationType;
use InFlow\ValueObjects\ColumnMapping;
use InFlow\ValueObjects\ModelMapping;

/**
 * Service for configuring relation lookups.
 *
 * Handles business logic for:
 * - Auto-detecting lookup fields for relations
 * - Verifying unique constraints via database introspection
 * - Configuring lookup based on relation type
 *
 * Presentation logic (logging, errors) is handled by the caller.
 */
readonly class RelationLookupService
{
    public function __construct(
        private RelationTypeService $relationTypeService
    ) {}

    /**
     * Configure relation lookup.
     *
     * Business logic: configures lookup for relations (explicit or auto-detected).
     *
     * @param  array{data: array, lookup: array|null, pivot: array<string, mixed>}  $relationInfo  The relation info (by reference)
     * @param  ColumnMapping  $columnMapping  The column mapping
     * @param  string  $relationName  The relation name
     * @param  string  $relationAttribute  The relation attribute
     * @param  ModelMapping  $mapping  The model mapping
     */
    public function configureLookup(
        array &$relationInfo,
        ColumnMapping $columnMapping,
        string $relationName,
        string $relationAttribute,
        ModelMapping $mapping
    ): void {
        // If relationLookup is explicitly configured, use it
        if ($columnMapping->relationLookup !== null) {
            $relationInfo['lookup'] = [
                'column' => $columnMapping,
                'field' => $columnMapping->relationLookup['field'] ?? $relationAttribute,
                'create_if_missing' => $columnMapping->relationLookup['create_if_missing'] ?? false,
                'delimiter' => $columnMapping->relationLookup['delimiter'] ?? null,
            ];

            return;
        }

        // Auto-detect based on relation type
        $relationType = $this->relationTypeService->getRelationType($mapping->modelClass, $relationName);

        if ($relationType === EloquentRelationType::BelongsTo) {
            $this->configureBelongsToLookup($relationInfo, $columnMapping, $relationAttribute);

            return;
        }

        if ($relationType === EloquentRelationType::HasMany) {
            $this->configureHasManyLookup($relationInfo, $columnMapping, $relationName, $relationAttribute, $mapping);
        }
    }

    /**
     * Configure lookup for BelongsTo relations.
     *
     * BelongsTo relations always use the mapped field as lookup.
     */
    private function configureBelongsToLookup(
        array &$relationInfo,
        ColumnMapping $columnMapping,
        string $relationAttribute
    ): void {
        $relationInfo['lookup'] = [
            'column' => $columnMapping,
            'field' => $relationAttribute,
            'create_if_missing' => false, // Default: don't create if missing
        ];
    }

    /**
     * Configure lookup for HasMany relations.
     *
     * Auto-detects unique fields in the related model via database introspection.
     */
    private function configureHasManyLookup(
        array &$relationInfo,
        ColumnMapping $columnMapping,
        string $relationName,
        string $relationAttribute,
        ModelMapping $mapping
    ): void {
        // Only set lookup if not already set (first unique field wins)
        if ($relationInfo['lookup'] !== null) {
            return;
        }

        // Check if field has unique constraint in related model
        if (! $this->isUniqueFieldInRelatedModel($mapping->modelClass, $relationName, $relationAttribute)) {
            return;
        }

        $relationInfo['lookup'] = [
            'column' => $columnMapping,
            'field' => $relationAttribute,
            'create_if_missing' => true, // Default: create if missing for HasMany
        ];
    }

    /**
     * Check if a field has a unique constraint in the related model's table.
     *
     * Uses database introspection to verify if the field is:
     * - Primary key
     * - Has a UNIQUE index
     *
     * @param  string  $parentModelClass  The parent model class
     * @param  string  $relationName  The relation name
     * @param  string  $fieldName  The field name to check
     * @return bool True if field is unique in related model
     */
    private function isUniqueFieldInRelatedModel(string $parentModelClass, string $relationName, string $fieldName): bool
    {
        try {
            // Get parent model instance
            if (! class_exists($parentModelClass)) {
                return false;
            }

            $parentModel = new $parentModelClass;

            // Check if relation method exists
            if (! method_exists($parentModel, $relationName)) {
                return false;
            }

            // Get relation instance
            $relation = $parentModel->$relationName();

            // Get related model class
            if (! method_exists($relation, 'getRelated')) {
                return false;
            }

            $relatedModel = $relation->getRelated();
            $table = $relatedModel->getTable();

            // Get primary key
            $primaryKey = $relatedModel->getKeyName();

            // Check if field is primary key
            if ($fieldName === $primaryKey) {
                return true;
            }

            // Check for unique indexes using database introspection
            $indexes = \DB::select("SHOW INDEX FROM `{$table}` WHERE Column_name = ? AND Non_unique = 0", [$fieldName]);

            return ! empty($indexes);
        } catch (\Exception $e) {
            // If introspection fails, fall back to false (no auto-detection)
            \inflow_report($e, 'debug', [
                'operation' => 'isUniqueFieldInRelatedModel',
                'parent_model' => $parentModelClass,
                'relation' => $relationName,
                'field' => $fieldName,
            ]);

            return false;
        }
    }
}
