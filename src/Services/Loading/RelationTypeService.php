<?php

namespace InFlow\Services\Loading;

use Illuminate\Database\Eloquent\Model;
use InFlow\Enums\Data\EloquentRelationType;

/**
 * Service for determining Eloquent relation types.
 *
 * Handles business logic for:
 * - Detecting relation types from model instances
 * - Checking if a relation is of a specific type
 *
 * Presentation logic (logging, errors) is handled by the caller.
 */
readonly class RelationTypeService
{
    /**
     * Get relation type from model and relation name.
     *
     * Business logic: instantiates model and checks relation type.
     *
     * @param  string  $modelClass  The model class
     * @param  string  $relationName  The relation name
     * @return EloquentRelationType|null The relation type, or null if relation doesn't exist
     */
    public function getRelationType(string $modelClass, string $relationName): ?EloquentRelationType
    {
        try {
            $model = new $modelClass;
            if (! method_exists($model, $relationName)) {
                return null;
            }

            $relation = $model->$relationName();
            $relationTypeName = get_class($relation);

            return $this->detectRelationType($relationTypeName);
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', [
                'operation' => 'getRelationType',
                'model' => $modelClass,
                'relation' => $relationName,
            ]);

            return null;
        }
    }

    /**
     * Check if a relation is of a specific type.
     *
     * Business logic: checks if relation matches the given type.
     *
     * @param  string  $modelClass  The model class
     * @param  string  $relationName  The relation name
     * @param  EloquentRelationType  $relationType  The relation type to check
     * @return bool True if relation is of the specified type
     */
    public function isRelationType(string $modelClass, string $relationName, EloquentRelationType $relationType): bool
    {
        $detectedType = $this->getRelationType($modelClass, $relationName);

        return $detectedType === $relationType;
    }

    /**
     * Detect relation type from relation class name.
     *
     * Business logic: maps relation class name to enum.
     *
     * @param  string  $relationTypeName  The relation class name (e.g., "Illuminate\Database\Eloquent\Relations\BelongsTo")
     * @return EloquentRelationType|null The relation type, or null if unknown
     */
    private function detectRelationType(string $relationTypeName): ?EloquentRelationType
    {
        // Prefer specific relation types over the generic "Relation" match
        $specificTypes = array_filter(
            EloquentRelationType::cases(),
            fn (EloquentRelationType $type) => $type !== EloquentRelationType::Relation
        );

        // Ensure more specific names are checked first (e.g. BelongsToMany before BelongsTo).
        usort(
            $specificTypes,
            fn (EloquentRelationType $a, EloquentRelationType $b) => strlen($b->value) <=> strlen($a->value)
        );

        foreach ($specificTypes as $relationType) {
            if ($relationType->matches($relationTypeName)) {
                return $relationType;
            }
        }

        return EloquentRelationType::Relation->matches($relationTypeName)
            ? EloquentRelationType::Relation
            : null;
    }
}
