<?php

namespace InFlow\Services\Mapping;

use InFlow\ValueObjects\MappingDefinition;
use InFlow\ValueObjects\ModelMapping;

/**
 * Validates mapping dependencies and execution order.
 *
 * Ensures that mappings are ordered correctly based on their dependencies.
 * For example, if Book mapping references Author, Author must have execution_order < Book.
 */
readonly class MappingDependencyValidator
{
    /**
     * Validate mapping dependencies and return errors if any.
     *
     * @return array<string> Array of error messages (empty if valid)
     */
    public function validate(MappingDefinition $mappingDefinition): array
    {
        $errors = [];
        $mappings = $mappingDefinition->mappings;

        // Group mappings by execution order
        $mappingsByOrder = [];
        foreach ($mappings as $mapping) {
            $order = $mapping->executionOrder;
            if (! isset($mappingsByOrder[$order])) {
                $mappingsByOrder[$order] = [];
            }
            $mappingsByOrder[$order][] = $mapping;
        }

        // Check for duplicate execution orders
        foreach ($mappingsByOrder as $order => $mappingsAtOrder) {
            if (count($mappingsAtOrder) > 1) {
                $modelClasses = array_map(fn (ModelMapping $m) => $m->modelClass, $mappingsAtOrder);
                $errors[] = "Multiple mappings have execution_order {$order}: ".implode(', ', $modelClasses);
            }
        }

        // Check dependencies
        foreach ($mappings as $mapping) {
            $dependencies = $this->extractDependencies($mapping);
            $mappingOrder = $mapping->executionOrder;

            foreach ($dependencies as $dependency) {
                $dependencyMapping = $this->findMappingForModel($mappings, $dependency['model']);
                if ($dependencyMapping === null) {
                    continue; // Dependency not in mappings, skip
                }

                $dependencyOrder = $dependencyMapping->executionOrder;
                if ($dependencyOrder >= $mappingOrder) {
                    $errors[] = "Mapping '{$mapping->modelClass}' (order {$mappingOrder}) depends on '{$dependency['model']}' (order {$dependencyOrder}), but dependency must execute first";
                }
            }
        }

        return $errors;
    }

    /**
     * Extract dependencies from a mapping.
     *
     * Returns array of ['model' => 'App\\Models\\Author', 'relation' => 'author']
     *
     * @return array<int, array{model: string, relation: string}>
     */
    private function extractDependencies(ModelMapping $mapping): array
    {
        $dependencies = [];

        foreach ($mapping->columns as $column) {
            $targetPath = $column->targetPath;

            // Check for relation paths (e.g., "author.email+", "author.name")
            if (str_contains($targetPath, '.')) {
                $parts = explode('.', $targetPath);
                $relationName = $parts[0];

                // Remove + suffix if present
                $relationName = rtrim($relationName, '+');

                // Get related model class
                $relatedModelClass = $this->getRelatedModelClass($mapping->modelClass, $relationName);
                if ($relatedModelClass !== null) {
                    $dependencies[] = [
                        'model' => $relatedModelClass,
                        'relation' => $relationName,
                    ];
                }
            }
        }

        // For pivot_sync, extract dependencies from relation path
        if ($mapping->type === 'pivot_sync' && $mapping->relationPath !== null) {
            $parts = explode('.', $mapping->relationPath);
            if (count($parts) === 2) {
                $modelClass = $parts[0]; // e.g., "Book" or "App\Models\Book"
                $relationName = $parts[1]; // e.g., "tags"

                // Try to resolve full model class if short name
                if (! str_contains($modelClass, '\\')) {
                    $modelClass = $this->resolveModelClass($mapping->modelClass, $modelClass);
                }

                if ($modelClass !== null) {
                    $relatedModelClass = $this->getRelatedModelClass($modelClass, $relationName);
                    if ($relatedModelClass !== null) {
                        $dependencies[] = [
                            'model' => $modelClass, // Parent model (Book)
                            'relation' => $relationName,
                        ];
                        $dependencies[] = [
                            'model' => $relatedModelClass, // Related model (Tag)
                            'relation' => $relationName,
                        ];
                    }
                }
            }
        }

        return $dependencies;
    }

    /**
     * Get related model class for a relation.
     */
    private function getRelatedModelClass(string $modelClass, string $relationName): ?string
    {
        if (! class_exists($modelClass)) {
            return null;
        }

        try {
            $model = new $modelClass;
            if (! method_exists($model, $relationName)) {
                return null;
            }

            $relation = $model->$relationName();
            $related = $relation->getRelated();

            return get_class($related);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Resolve model class from short name or context.
     */
    private function resolveModelClass(string $contextModelClass, string $modelName): ?string
    {
        // Try App\Models\{ModelName}
        $fullClass = "App\\Models\\{$modelName}";
        if (class_exists($fullClass)) {
            return $fullClass;
        }

        // Try same namespace as context
        $namespace = substr($contextModelClass, 0, strrpos($contextModelClass, '\\'));
        $fullClass = "{$namespace}\\{$modelName}";
        if (class_exists($fullClass)) {
            return $fullClass;
        }

        return null;
    }

    /**
     * Find mapping for a model class.
     */
    private function findMappingForModel(array $mappings, string $modelClass): ?ModelMapping
    {
        foreach ($mappings as $mapping) {
            if ($mapping->modelClass === $modelClass) {
                return $mapping;
            }
        }

        return null;
    }
}

