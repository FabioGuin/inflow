<?php

namespace InFlow\Services\Mapping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InFlow\Services\File\ModelSelectionService;

/**
 * Service for extracting model cast information and detecting conflicts with transforms.
 *
 * Handles business logic for:
 * - Extracting cast types from model casts() method
 * - Detecting conflicts between transforms and model casts
 * - Providing warnings about Laravel cast precedence
 */
readonly class ModelCastService
{
    public function __construct(
        private ModelSelectionService $modelSelectionService
    ) {}

    /**
     * Get cast information for a target field from the model.
     *
     * If no cast is defined, falls back to database column type.
     * Also includes database column type for filtering transforms based on actual DB type.
     *
     * @param  string  $modelClass  The model class name
     * @param  string  $targetPath  The target field path (e.g., 'price' or 'category.name')
     * @return array{type: string|null, precision: int|null, raw: string|null, fromDatabase: bool, dbType: string|null} Cast information
     */
    public function getCastInfo(string $modelClass, string $targetPath): array
    {
        if (! class_exists($modelClass)) {
            return ['type' => null, 'precision' => null, 'raw' => null, 'fromDatabase' => false, 'dbType' => null];
        }

        // Always get database type for filtering transforms
        $dbType = $this->getDatabaseColumnType($modelClass, $targetPath);

        // Handle relation paths (e.g., 'category.name')
        if (str_contains($targetPath, '.')) {
            [$relationName, $fieldName] = explode('.', $targetPath, 2);
            $relations = $this->modelSelectionService->getModelRelations($modelClass);

            if (! isset($relations[$relationName])) {
                return ['type' => null, 'precision' => null, 'raw' => null, 'fromDatabase' => false, 'dbType' => $dbType];
            }

            $relatedModelClass = $relations[$relationName];
            $relatedModel = new $relatedModelClass;

            $castInfo = $this->getCastInfoFromModel($relatedModel, $fieldName);
        } else {
            // Direct field path
            $model = new $modelClass;
            $castInfo = $this->getCastInfoFromModel($model, $targetPath);
        }

        // If cast is defined, return it with DB type for filtering
        if ($castInfo['type'] !== null) {
            return array_merge($castInfo, ['fromDatabase' => false, 'dbType' => $dbType]);
        }

        // Fallback to database column type
        if ($dbType !== null) {
            // Extract precision for decimal types from database
            $precision = null;
            if ($dbType === 'decimal') {
                $precision = $this->extractDecimalPrecisionFromDatabase($modelClass, $targetPath);
            }

            return [
                'type' => $dbType,
                'precision' => $precision,
                'raw' => null,
                'fromDatabase' => true,
                'dbType' => $dbType,
            ];
        }

        return array_merge($castInfo, ['fromDatabase' => false, 'dbType' => $dbType]);
    }

    /**
     * Get cast type for a target field (simplified version for backward compatibility).
     *
     * Returns only the cast type string (e.g., 'bool', 'int', 'float', 'date') without precision.
     * For full information including precision, use getCastInfo().
     *
     * If no cast is defined on the model, falls back to database column type.
     *
     * @param  string  $modelClass  The model class name
     * @param  string  $targetPath  The target field path (e.g., 'price' or 'category.name')
     * @return string|null The cast type (e.g., 'bool', 'int', 'float', 'date') or null
     */
    public function getCastType(string $modelClass, string $targetPath): ?string
    {
        $castInfo = $this->getCastInfo($modelClass, $targetPath);

        // If cast is defined, return it
        if ($castInfo['type'] !== null) {
            return $castInfo['type'];
        }

        // Fallback to database column type if no cast is defined
        return $this->getDatabaseColumnType($modelClass, $targetPath);
    }

    /**
     * Check if a transform conflicts with model cast.
     *
     * @param  string  $transformSpec  Transform specification (e.g., 'round:1')
     * @param  string  $modelClass  The model class name
     * @param  string  $targetPath  The target field path
     * @return array{conflicts: bool, message: string|null} Conflict information
     */
    public function checkTransformCastConflict(string $transformSpec, string $modelClass, string $targetPath): array
    {
        $castInfo = $this->getCastInfo($modelClass, $targetPath);

        // Check for round transform conflicts with decimal cast
        if (str_starts_with($transformSpec, 'round:')) {
            $parts = explode(':', $transformSpec, 2);
            $roundPrecision = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;

            if ($castInfo['type'] === 'decimal' && $castInfo['precision'] !== null) {
                if ($roundPrecision !== $castInfo['precision']) {
                    return [
                        'conflicts' => true,
                        'message' => "Transform `round:{$roundPrecision}` will be overridden by model cast `decimal:{$castInfo['precision']}`. Laravel model casts take precedence over transforms.",
                    ];
                }
            }
        }

        return ['conflicts' => false, 'message' => null];
    }

    /**
     * Get cast information from model casts() method.
     *
     * @param  Model  $model  The model instance
     * @param  string  $fieldName  The field name
     * @return array{type: string|null, precision: int|null, raw: string|null} Cast information
     */
    private function getCastInfoFromModel(Model $model, string $fieldName): array
    {
        try {
            $reflection = new \ReflectionClass($model);

            // Try to get casts() method (protected in Laravel)
            if (! $reflection->hasMethod('casts')) {
                return ['type' => null, 'precision' => null, 'raw' => null];
            }

            $castsMethod = $reflection->getMethod('casts');
            // Make protected method accessible for invocation
            $castsMethod->setAccessible(true);
            $casts = $castsMethod->invoke($model);

            if (! is_array($casts) || ! isset($casts[$fieldName])) {
                return ['type' => null, 'precision' => null, 'raw' => null];
            }

            $cast = $casts[$fieldName];
            $raw = $cast;

            // Parse cast type and precision (centralized logic)
            $parsed = self::parseCastType($cast);
            $type = $parsed['type'];
            $precision = $parsed['precision'];

            return ['type' => $type, 'precision' => $precision, 'raw' => $raw];
        } catch (\ReflectionException $e) {
            \inflow_report($e, 'debug', [
                'operation' => 'getCastInfoFromModel',
                'model' => get_class($model),
                'field' => $fieldName,
            ]);

            return ['type' => null, 'precision' => null, 'raw' => null];
        }
    }

    /**
     * Extract decimal precision from database column definition.
     *
     * @param  string  $modelClass  The model class name
     * @param  string  $targetPath  The target field path
     * @return int|null The precision (number of decimal places) or null
     */
    private function extractDecimalPrecisionFromDatabase(string $modelClass, string $targetPath): ?int
    {
        if (! class_exists($modelClass)) {
            return null;
        }

        try {
            $model = new $modelClass;
            $table = $model->getTable();
            $fieldName = $targetPath;

            // Handle relation paths
            if (str_contains($targetPath, '.')) {
                [$relationName, $fieldName] = explode('.', $targetPath, 2);
                $relations = $this->modelSelectionService->getModelRelations($modelClass);

                if (! isset($relations[$relationName])) {
                    return null;
                }

                $relatedModelClass = $relations[$relationName];
                $relatedModel = new $relatedModelClass;
                $table = $relatedModel->getTable();
            }

            $columns = DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$fieldName]);

            if (empty($columns)) {
                return null;
            }

            $column = $columns[0];
            $dbType = $column->Type ?? '';

            // Use parseCastType to extract precision (DRY principle)
            $parsed = self::parseCastType($dbType);

            return $parsed['precision'];
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', [
                'operation' => 'extractDecimalPrecisionFromDatabase',
                'model' => $modelClass,
                'field' => $targetPath,
            ]);

            return null;
        }
    }

    /**
     * Parse Laravel cast type string into normalized type and precision.
     *
     * Centralized logic for parsing cast types (DRY principle).
     * Handles: 'boolean', 'integer', 'float', 'decimal:X', 'datetime', etc.
     * Also handles database column types (e.g., 'int', 'varchar(255)', 'decimal(10,2)').
     *
     * @param  string  $cast  The raw cast string from model (e.g., 'decimal:2', 'boolean') or database type (e.g., 'int', 'decimal(10,2)')
     * @return array{type: string|null, precision: int|null} Parsed cast information
     */
    public static function parseCastType(string $cast): array
    {
        $normalized = strtolower(trim($cast));

        // Handle Laravel cast types: 'boolean', 'integer', 'float', 'decimal:X', 'datetime', etc.
        // Also handle database column types: 'int', 'varchar(255)', 'decimal(10,2)', etc.
        $type = match (true) {
            // Boolean types
            $normalized === 'boolean' || $normalized === 'bool' => 'bool',
            str_starts_with($normalized, 'tinyint') && str_contains($normalized, '1') => 'bool',

            // Integer types
            in_array($normalized, ['int', 'integer'], true) => 'int',
            in_array($normalized, ['tinyint', 'smallint', 'mediumint', 'bigint'], true) => 'int',

            // Float types
            in_array($normalized, ['float', 'double', 'real'], true) => 'float',

            // Decimal types (Laravel cast: 'decimal:X' or database: 'decimal(10,2)')
            str_starts_with($normalized, 'decimal') || str_starts_with($normalized, 'numeric') => 'decimal',

            // Date/time types
            in_array($normalized, ['date', 'datetime', 'timestamp', 'time', 'year'], true) => 'date',
            str_starts_with($normalized, 'datetime:') || str_starts_with($normalized, 'date:') => 'date',

            // String types (database only)
            in_array($normalized, ['varchar', 'char', 'text', 'longtext', 'mediumtext', 'tinytext', 'json', 'jsonb'], true) => 'string',

            default => null,
        };

        // Extract precision for decimal casts
        // Laravel format: 'decimal:2' -> precision = 2
        // Database format: 'decimal(10,2)' -> precision = 2 (second number)
        $precision = null;
        if ($type === 'decimal') {
            // Try Laravel format first (decimal:X)
            if (preg_match('/decimal:(\d+)/', $cast, $matches)) {
                $precision = (int) $matches[1];
            }
            // Try database format (decimal(10,2) or numeric(10,2))
            elseif (preg_match('/(?:decimal|numeric)\((?:\d+),(\d+)\)/i', $cast, $matches)) {
                $precision = (int) $matches[1];
            }
        }

        return ['type' => $type, 'precision' => $precision];
    }

    /**
     * Get database column type for a target field.
     *
     * Reads the column type from the database schema when no cast is defined.
     * Converts database types (e.g., 'int', 'varchar(255)', 'decimal(10,2)') to normalized types.
     *
     * @param  string  $modelClass  The model class name
     * @param  string  $targetPath  The target field path (e.g., 'price' or 'category.name')
     * @return string|null The normalized type (e.g., 'int', 'string', 'float', 'decimal', 'date', 'bool') or null
     */
    private function getDatabaseColumnType(string $modelClass, string $targetPath): ?string
    {
        if (! class_exists($modelClass)) {
            return null;
        }

        try {
            $model = new $modelClass;
            $table = $model->getTable();
            $fieldName = $targetPath;

            // Handle relation paths (e.g., 'category.name')
            if (str_contains($targetPath, '.')) {
                [$relationName, $fieldName] = explode('.', $targetPath, 2);
                $relations = $this->modelSelectionService->getModelRelations($modelClass);

                if (! isset($relations[$relationName])) {
                    return null;
                }

                $relatedModelClass = $relations[$relationName];
                $relatedModel = new $relatedModelClass;
                $table = $relatedModel->getTable();
            }

            // Get column type from database
            $columns = DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$fieldName]);

            if (empty($columns)) {
                return null;
            }

            $column = $columns[0];
            $dbType = $column->Type ?? '';

            // Use parseCastType to normalize database type (DRY principle)
            $parsed = self::parseCastType($dbType);

            return $parsed['type'];
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', [
                'operation' => 'getDatabaseColumnType',
                'model' => $modelClass,
                'field' => $targetPath,
            ]);

            return null;
        }
    }
}
