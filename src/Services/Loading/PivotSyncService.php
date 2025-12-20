<?php

namespace InFlow\Services\Loading;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InFlow\Transforms\TransformEngine;
use InFlow\ValueObjects\Data\ColumnMapping;
use InFlow\ValueObjects\Data\Row;
use InFlow\ValueObjects\Mapping\ModelMapping;

/**
 * Service for synchronizing many-to-many pivot relations.
 *
 * Handles pivot_sync type mappings that sync relations without creating models.
 */
readonly class PivotSyncService
{
    public function __construct(
        private TransformEngine $transformEngine,
        private ModelPersistenceService $modelPersistenceService
    ) {}

    /**
     * Sync pivot relation from row data.
     *
     * @param  Row  $row  The row data
     * @param  ModelMapping  $mapping  The pivot_sync mapping
     */
    public function sync(Row $row, ModelMapping $mapping): void
    {
        if ($mapping->type !== 'pivot_sync' || $mapping->relationPath === null) {
            return;
        }

        // Parse relation path (e.g., "Book.tags" or "App\Models\Book.tags")
        $parts = explode('.', $mapping->relationPath);
        if (count($parts) !== 2) {
            return;
        }

        $parentModelClass = $parts[0];
        $relationName = $parts[1];

        // Resolve full model class if short name
        if (! str_contains($parentModelClass, '\\')) {
            $parentModelClass = "App\\Models\\{$parentModelClass}";
        }

        if (! class_exists($parentModelClass)) {
            return;
        }

        // Extract parent and related model lookups from columns
        $parentLookup = null;
        $relatedLookup = null;
        $pivotData = [];

        foreach ($mapping->columns as $column) {
            $targetPath = $column->targetPath;

            // Check for pivot data (pivot_* columns)
            if (str_starts_with($targetPath, 'pivot_')) {
                $pivotKey = substr($targetPath, 6); // Remove "pivot_" prefix
                $value = $this->extractAndTransformValue($row, $column);
                if ($value !== null && $value !== '') {
                    $pivotData[$pivotKey] = $value;
                }

                continue;
            }

            // Check for parent lookup (e.g., "book.isbn")
            if (str_starts_with($targetPath, 'book.') || str_starts_with($targetPath, 'parent.')) {
                $parentField = explode('.', $targetPath)[1];
                if ($parentLookup === null) {
                    $parentLookup = [
                        'field' => $parentField,
                        'value' => $this->extractAndTransformValue($row, $column),
                        'lookup' => $column->relationLookup,
                    ];
                }

                continue;
            }

            // Check for related lookup (e.g., "tag.slug")
            if (str_starts_with($targetPath, 'tag.') || str_starts_with($targetPath, 'related.')) {
                $relatedField = explode('.', $targetPath)[1];
                if ($relatedLookup === null) {
                    $relatedLookup = [
                        'field' => $relatedField,
                        'value' => $this->extractAndTransformValue($row, $column),
                        'lookup' => $column->relationLookup,
                    ];
                }
            }
        }

        if ($parentLookup === null || $relatedLookup === null) {
            return;
        }

        // Find parent model
        $parentModel = $this->findModel($parentModelClass, $parentLookup['field'], $parentLookup['value'], $parentLookup['lookup']);
        if ($parentModel === null) {
            return;
        }

        // Get relation
        $relation = $parentModel->$relationName();
        if (! $relation instanceof BelongsToMany) {
            return;
        }

        // Find related model
        $relatedModelClass = get_class($relation->getRelated());
        $relatedModel = $this->findModel(
            $relatedModelClass,
            $relatedLookup['field'],
            $relatedLookup['value'],
            $relatedLookup['lookup']
        );

        if ($relatedModel === null) {
            return;
        }

        // Sync relation with pivot data
        $syncData = [$relatedModel->getKey() => $pivotData];
        $relation->syncWithoutDetaching($syncData);
    }

    /**
     * Find model by lookup field and value.
     */
    private function findModel(string $modelClass, string $field, mixed $value, ?array $lookup): ?Model
    {
        if ($value === null || $value === '') {
            return null;
        }

        $model = $modelClass::where($field, $value)->first();

        if ($model === null && ($lookup['create_if_missing'] ?? false)) {
            $createData = [$field => $value];
            $model = $modelClass::firstOrCreate([$field => $value], $createData);
        }

        return $model;
    }

    /**
     * Extract and transform value from row.
     */
    private function extractAndTransformValue(Row $row, ColumnMapping $column): mixed
    {
        $value = $row->get($column->sourceColumn);

        if ($value === null || $value === '') {
            $value = $column->default;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return $this->transformEngine->apply($value, $column->transforms, ['row' => $row->toArray()]);
    }
}
