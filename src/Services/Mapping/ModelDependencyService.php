<?php

namespace InFlow\Services\Mapping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InFlow\Enums\Data\EloquentRelationType;
use InFlow\Services\File\ModelSelectionService;
use InFlow\Services\Loading\RelationTypeService;

use function app_path;

/**
 * Service for analyzing model dependencies and suggesting root models.
 *
 * Analyzes BelongsTo relationships to determine:
 * - Which models are "root" (no BelongsTo dependencies)
 * - Which models have dependencies and what they require
 */
readonly class ModelDependencyService
{
    public function __construct(
        private ModelSelectionService $modelSelectionService,
        private RelationTypeService $relationTypeService
    ) {}

    /**
     * Analyze BelongsTo dependencies for a model.
     *
     * @param  string  $modelClass  The fully qualified model class name
     * @return array{
     *     belongsTo: array<string, string>,  // relation_name => related_model_class
     *     isRoot: bool,
     *     requiredDependencies: array<string>,  // List of required related model classes
     *     optionalDependencies: array<string>   // List of optional related model classes
     * }
     */
    public function analyzeDependencies(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [
                'belongsTo' => [],
                'isRoot' => false,
                'requiredDependencies' => [],
                'optionalDependencies' => [],
            ];
        }

        $allRelations = $this->modelSelectionService->getModelRelations($modelClass);
        $belongsToRelations = [];
        $requiredDependencies = [];
        $optionalDependencies = [];

        foreach ($allRelations as $relationName => $relatedModelClass) {
            $relationType = $this->relationTypeService->getRelationType($modelClass, $relationName);

            if ($relationType === EloquentRelationType::BelongsTo) {
                $belongsToRelations[$relationName] = $relatedModelClass;

                // Check if the foreign key is nullable
                $isNullable = $this->isBelongsToNullable($modelClass, $relationName);

                if ($isNullable) {
                    $optionalDependencies[] = $relatedModelClass;
                } else {
                    $requiredDependencies[] = $relatedModelClass;
                }
            }
        }

        return [
            'belongsTo' => $belongsToRelations,
            'isRoot' => empty($belongsToRelations),
            'requiredDependencies' => $requiredDependencies,
            'optionalDependencies' => $optionalDependencies,
        ];
    }

    /**
     * Check if a model is a root model (no BelongsTo dependencies).
     *
     * @param  string  $modelClass  The fully qualified model class name
     * @return bool True if the model is a root model
     */
    public function isRootModel(string $modelClass): bool
    {
        $analysis = $this->analyzeDependencies($modelClass);

        return $analysis['isRoot'];
    }

    /**
     * Get all root models from a namespace.
     *
     * Scans all models in the given namespace and returns those without BelongsTo dependencies.
     *
     * @param  string  $namespace  The namespace to scan (e.g., "App\Models")
     * @return array<string> Array of root model class names
     */
    public function findRootModels(string $namespace = 'App\\Models'): array
    {
        $rootModels = [];
        $allModels = $this->getAllModelsInNamespace($namespace);

        foreach ($allModels as $modelClass) {
            if ($this->isRootModel($modelClass)) {
                $rootModels[] = $modelClass;
            }
        }

        return $rootModels;
    }

    /**
     * Get all Eloquent models in a namespace.
     *
     * Scans the namespace directory and returns all valid Eloquent model classes.
     *
     * @param  string  $namespace  The namespace to scan (e.g., "App\Models")
     * @return array<string> Array of model class names
     */
    private function getAllModelsInNamespace(string $namespace): array
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
     * Check if a BelongsTo relation is nullable by analyzing the database schema.
     *
     * @param  string  $modelClass  The model class
     * @param  string  $relationName  The BelongsTo relation name
     * @return bool True if the foreign key column is nullable
     */
    private function isBelongsToNullable(string $modelClass, string $relationName): bool
    {
        try {
            $model = new $modelClass;
            if (! method_exists($model, $relationName)) {
                return false;
            }

            $relation = $model->$relationName();
            $foreignKey = $relation->getForeignKeyName();
            $table = $model->getTable();

            // Query database to check if column is nullable
            $columns = DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$foreignKey]);

            if (empty($columns)) {
                // Column doesn't exist, assume required
                return false;
            }

            $column = $columns[0];
            $null = $column->Null ?? 'NO';

            return strtoupper($null) === 'YES';
        } catch (\Exception $e) {
            // On error, assume required (safer default)
            \inflow_report($e, 'debug', [
                'operation' => 'isBelongsToNullable',
                'model' => $modelClass,
                'relation' => $relationName,
            ]);

            return false;
        }
    }
}

