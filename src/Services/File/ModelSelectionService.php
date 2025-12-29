<?php

namespace InFlow\Services\File;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use InFlow\Enums\Data\EloquentRelationType;

/**
 * Service for model class selection, normalization, and validation.
 *
 * Handles the business logic of discovering, normalizing, and validating
 * Eloquent model classes. Presentation logic (prompts, display) is handled
 * by the caller.
 */
class ModelSelectionService
{
    /**
     * Normalize model class: handle escaped backslashes and reconstruct FQCN.
     *
     * Handles various input formats:
     * - Escaped backslashes from command line (e.g., App\\Models\\User)
     * - Missing backslashes (e.g., AppModelsUser -> App\Models\User)
     * - Common namespace patterns (App\Models, App\Http, etc.)
     *
     * @param  string  $modelClass  The model class string to normalize
     * @return string Normalized fully qualified class name
     */
    public function normalizeModelClass(string $modelClass): string
    {
        // Handle escaped backslashes from command line (e.g., App\\Models\\User)
        $modelClass = str_replace('\\\\', '\\', $modelClass);

        // If backslashes were removed by shell (e.g., AppModelsUser), try to reconstruct
        // This is a heuristic: if no backslashes and starts with App, try common patterns
        if (! str_contains($modelClass, '\\') && str_starts_with($modelClass, 'App')) {
            // Try to insert backslashes at common boundaries
            // AppModelsUser -> App\Models\User
            $modelClass = preg_replace_callback('/([a-z])([A-Z])/', function ($matches) {
                return $matches[1].'\\'.$matches[2];
            }, $modelClass);
            $modelClass = str_replace('AppModels', 'App\\Models', $modelClass);
        }

        // Final validation: ensure we have a valid FQCN
        if (! class_exists($modelClass)) {
            // Try one more time with a simpler approach
            if (preg_match('/^App(Models|Http|Console|Services|Repositories)(.+)$/', $modelClass, $matches)) {
                $namespace = $matches[1];
                $className = $matches[2];
                $modelClass = 'App\\'.$namespace.'\\'.$className;
            }
        }

        return $modelClass;
    }

    /**
     * Validate that a class exists and is an Eloquent model.
     *
     * @param  string  $modelClass  The fully qualified class name to validate
     * @return string|null Error message if validation fails, null if valid
     */
    public function validateModelClass(string $modelClass): ?string
    {
        $normalized = $this->normalizeModelClass($modelClass);

        if (! class_exists($normalized)) {
            return "Model class '{$normalized}' does not exist.";
        }

        // Check if it's an Eloquent model
        if (! is_subclass_of($normalized, Model::class)) {
            return "Class '{$normalized}' is not an Eloquent model.";
        }

        return null;
    }

    /**
     * Get all Eloquent models in a namespace.
     *
     * @param  string  $namespace  The namespace to scan (e.g., "App\Models")
     * @return array<string> Array of fully qualified model class names
     */
    public function getAllModelsInNamespace(string $namespace = 'App\\Models'): array
    {
        $models = [];

        // Convert namespace to directory path
        // App\Models -> app/Models
        $namespaceParts = explode('\\', $namespace);
        $basePath = app_path();
        $relativePath = implode('/', array_slice($namespaceParts, 1)); // Remove "App"
        $directory = $basePath.'/'.$relativePath;

        if (! is_dir($directory)) {
            return [];
        }

        // Scan directory for PHP files
        $files = glob($directory.'/*.php');

        foreach ($files as $file) {
            $className = basename($file, '.php');
            $modelClass = $namespace.'\\'.$className;

            // Check if class exists and is an Eloquent model
            if (class_exists($modelClass) && is_subclass_of($modelClass, Model::class)) {
                $models[] = $modelClass;
            }
        }

        return $models;
    }

    /**
     * Get model relations with their related model classes.
     *
     * Uses reflection to discover Eloquent relations by analyzing method return types.
     * Supports: HasOne, BelongsTo, HasMany, BelongsToMany, MorphTo, MorphOne, MorphMany.
     *
     * @param  string  $modelClass  The fully qualified model class name
     * @return array<string, string> Relation name => Related model class
     */
    public function getModelRelations(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        try {
            $model = new $modelClass;
            $reflection = new \ReflectionClass($model);
            $relations = [];

            foreach ($reflection->getMethods() as $method) {
                // Skip non-public methods
                if (! $method->isPublic()) {
                    continue;
                }

                // Skip methods with parameters
                if ($method->getNumberOfParameters() > 0) {
                    continue;
                }

                $returnType = $method->getReturnType();
                if ($returnType === null) {
                    continue;
                }

                $returnTypeName = $returnType->getName();

                // Check if it's a relation type
                if ($this->isRelationType($returnTypeName)) {
                    $methodName = $method->getName();

                    // Try to get related model class
                    try {
                        $relation = $model->$methodName();
                        if (method_exists($relation, 'getRelated')) {
                            $related = $relation->getRelated();
                            $relations[$methodName] = get_class($related);
                        }
                    } catch (\Exception $e) {
                        // Skip if relation can't be resolved (log for debugging)
                        \inflow_report($e, 'debug', [
                            'model' => $modelClass,
                            'relation' => $methodName,
                        ]);

                        continue;
                    }
                }
            }

            return $relations;
        } catch (\Exception $e) {
            // Log error but return empty array to allow graceful degradation
            \inflow_report($e, 'warning', [
                'model' => $modelClass,
                'operation' => 'getModelRelations',
            ]);

            return [];
        }
    }

    /**
     * Get all available attributes from a model.
     *
     * Returns all database columns for the model, excluding only system fields
     * (id, timestamps) which are not typically filled during ETL operations.
     *
     * This provides complete visibility into all available fields for mapping,
     * not just fillable attributes, giving more flexibility in ETL operations.
     *
     * @param  string  $modelClass  The fully qualified model class name
     * @return array<string> Array of all available attribute names
     */
    public function getAllModelAttributes(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        try {
            $model = new $modelClass;
            $table = $model->getTable();

            // Get all columns from database schema
            $columns = Schema::getColumnListing($table);

            // Always exclude system fields (id, timestamps) as they are not typically filled in ETL
            $excluded = ['id', 'created_at', 'updated_at', 'deleted_at'];
            $attributes = array_diff($columns, $excluded);

            return array_values($attributes);
        } catch (\Exception $e) {
            // Log error but return empty array to allow graceful degradation
            \inflow_report($e, 'warning', [
                'model' => $modelClass,
                'operation' => 'getAllModelAttributes',
            ]);

            return [];
        }
    }

    /**
     * Check if a return type name represents an Eloquent relation.
     *
     * @param  string  $returnTypeName  The return type name to check
     * @return bool True if it's a relation type
     */
    private function isRelationType(string $returnTypeName): bool
    {
        return EloquentRelationType::isRelationType($returnTypeName);
    }
}
