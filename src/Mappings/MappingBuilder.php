<?php

namespace InFlow\Mappings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InFlow\Enums\ColumnType;
use InFlow\Services\Mapping\ModelCastService;
use InFlow\ValueObjects\ColumnMapping;
use InFlow\ValueObjects\ColumnMetadata;
use InFlow\ValueObjects\MappingDefinition;
use InFlow\ValueObjects\ModelMapping;
use InFlow\ValueObjects\SourceSchema;

/**
 * Builder for creating mapping definitions with auto-mapping support
 *
 * This class provides a DSL-like (Domain-Specific Language) interface for defining
 * mappings between source columns and Eloquent models. The fluent interface allows
 * for intuitive and readable mapping definitions.
 *
 * Example usage (Fluent Interface / DSL-like):
 * ```php
 * $mapping = MappingBuilder::for(User::class)
 *     ->map('email', 'email', ['trim', 'lower'])
 *     ->map('name', 'name', ['trim'])
 *     ->map('age', 'age', ['cast:int'])
 *     ->build();
 * ```
 *
 * Or using auto-mapping with interactive callbacks:
 * ```php
 * $builder = new MappingBuilder();
 * $mapping = $builder->autoMapInteractive($schema, User::class, $callback);
 * ```
 */
readonly class MappingBuilder
{
    public function __construct(
        private MappingSuggestionEngine $suggestionEngine,
        private ModelCastService $modelCastService
    ) {}

    /**
     * Create a fluent builder for a model mapping (DSL entry point)
     *
     * This static method provides the entry point for the fluent DSL-like interface.
     * It returns a ModelMappingBuilder instance that allows chaining mapping definitions.
     *
     * @param  string  $modelClass  Fully qualified class name of the Eloquent model
     * @return ModelMappingBuilder Fluent builder instance for method chaining
     *
     * @example
     * ```php
     * $mapping = MappingBuilder::for(User::class)
     *     ->map('email', 'email', ['trim', 'lower'])
     *     ->map('name', 'name', ['trim'])
     *     ->build();
     * ```
     */
    public static function for(string $modelClass): ModelMappingBuilder
    {
        return new ModelMappingBuilder($modelClass);
    }

    /**
     * Auto-map columns from source schema to model with interactive confirmation
     *
     * @param  callable|null  $interactiveCallback  Callback for path confirmation: (sourceColumn, suggestedPath, confidence, alternatives, isRelation, isArrayRelation, columnMeta) => bool|string|array{path: string, delimiter?: string}
     * @param  callable|null  $transformCallback  Callback for transform selection: (sourceColumn, targetPath, suggestedTransforms, columnMeta, targetType) => array<string>
     */
    public function autoMapInteractive(
        SourceSchema $schema,
        string $modelClass,
        ?callable $interactiveCallback = null,
        ?callable $transformCallback = null
    ): MappingDefinition {
        $suggestions = $this->suggestionEngine->suggestMappings($schema, $modelClass);
        $columns = [];
        $mappedPaths = [];

        foreach ($suggestions as $sourceColumn => $suggestion) {
            $columnMeta = $schema->getColumn($sourceColumn);
            $alternatives = $this->suggestionEngine->generateAlternativesForColumn(
                $suggestion['path'],
                $modelClass,
                $mappedPaths
            );

            // Process interactive confirmation
            $confirmationResult = $this->processInteractiveConfirmation(
                $interactiveCallback,
                $sourceColumn,
                $suggestion,
                $alternatives,
                $columnMeta
            );

            if ($confirmationResult === null) {
                continue; // Skip this column
            }

            [$finalPath, $delimiter] = $confirmationResult;
            $mappedPaths[] = $finalPath;

            // Process transform selection
            $transforms = $this->processTransformSelection(
                $transformCallback,
                $sourceColumn,
                $finalPath,
                $columnMeta,
                $modelClass
            );

            // Parse relation lookup and clean path
            [$cleanPath, $relationLookup] = $this->parseRelationLookup($finalPath, $delimiter, $modelClass);

            // Build column mapping
            $columns[] = $this->buildColumnMapping(
                $sourceColumn,
                $cleanPath,
                $transforms,
                $modelClass,
                $relationLookup
            );
        }

        return new MappingDefinition(
            mappings: [
                new ModelMapping(
                    modelClass: $modelClass,
                    columns: $columns
                ),
            ],
            sourceSchema: $schema
        );
    }

    /**
     * Process interactive confirmation callback.
     *
     * @return array{0: string, 1: string|null}|null Returns [path, delimiter] or null if skipped
     */
    private function processInteractiveConfirmation(
        ?callable $interactiveCallback,
        string $sourceColumn,
        array $suggestion,
        array $alternatives,
        ?ColumnMetadata $columnMeta
    ): ?array {
        if ($interactiveCallback === null) {
            return [$suggestion['path'], null];
        }

        $confirmed = $interactiveCallback(
            $sourceColumn,
            $suggestion['path'],
            $suggestion['confidence'],
            $alternatives,
            $suggestion['is_relation'] ?? false,
            $suggestion['is_array_relation'] ?? false,
            $columnMeta
        );

        if ($confirmed === false) {
            return null; // Skip this column
        }

        if (is_array($confirmed)) {
            return [
                $confirmed['path'] ?? $suggestion['path'],
                $confirmed['delimiter'] ?? null,
            ];
        }

        if (is_string($confirmed)) {
            return [$confirmed, null];
        }

        return [$suggestion['path'], null];
    }

    /**
     * Process transform selection callback.
     *
     * @return array<string>
     */
    private function processTransformSelection(
        ?callable $transformCallback,
        string $sourceColumn,
        string $targetPath,
        ?ColumnMetadata $columnMeta,
        string $modelClass
    ): array {
        $suggestedTransforms = $this->suggestTransforms($columnMeta, $modelClass, $targetPath);

        if ($transformCallback === null) {
            return $suggestedTransforms;
        }

        $targetType = $this->modelCastService->getCastType($modelClass, $targetPath);

        return $transformCallback(
            $sourceColumn,
            $targetPath,
            $suggestedTransforms,
            $columnMeta,
            $targetType,
            $modelClass
        );
    }

    /**
     * Parse relation lookup configuration from path and delimiter.
     *
     * @return array{0: string, 1: array<string, mixed>|null} Returns [cleanPath, relationLookup]
     */
    private function parseRelationLookup(string $path, ?string $delimiter, string $modelClass = ''): array
    {
        $cleanPath = $path;
        $relationLookup = null;

        // Handle relation.* (full array mapping) - needs relation_lookup with create_if_missing
        if (str_ends_with($path, '.*')) {
            $relationName = substr($path, 0, -2); // Remove .*
            $lookupField = $this->findBestLookupFieldForRelation($modelClass, $relationName);
            $relationLookup = [
                'field' => $lookupField,
                'create_if_missing' => true,
            ];
            return [$cleanPath, $relationLookup];
        }

        // Parse create_if_missing suffix (+) for BelongsTo/BelongsToMany relations
        if (str_ends_with($path, '+')) {
            $cleanPath = substr($path, 0, -1);
            $pathParts = explode('.', $cleanPath);
            if (count($pathParts) >= 2) {
                $relationLookup = [
                    'field' => $pathParts[1],
                    'create_if_missing' => true,
                ];
            }
        }

        // Add delimiter for multi-value BelongsToMany
        if ($delimiter !== null) {
            $relationLookup ??= [];
            $pathParts = explode('.', $cleanPath);
            if (count($pathParts) >= 2) {
                $relationLookup['field'] ??= $pathParts[1];
            }
            $relationLookup['delimiter'] = $delimiter;
        }

        // For relation.field paths without + suffix, still configure relation_lookup
        // This enables automatic lookup/create in non-interactive mode
        if ($relationLookup === null && str_contains($cleanPath, '.')) {
            $pathParts = explode('.', $cleanPath);
            // Check if this looks like a relation path (e.g., author.name, category.title)
            if (count($pathParts) === 2 && $pathParts[1] !== 'pivot' && ! str_starts_with($pathParts[1], '?')) {
                $relationLookup = [
                    'field' => $pathParts[1],
                    'create_if_missing' => true, // Default to true for convenience in non-interactive mode
                ];
            }
        }

        return [$cleanPath, $relationLookup];
    }

    /**
     * Find the best lookup field for a relation by introspecting the related model.
     *
     * Completely agnostic approach - no hardcoded field names:
     * 1. First fillable field with a unique index (excluding primary key)
     * 2. First fillable field (if no unique found)
     */
    private function findBestLookupFieldForRelation(string $modelClass, string $relationName): string
    {
        if (empty($modelClass) || ! class_exists($modelClass)) {
            return $this->getFallbackLookupField($modelClass, $relationName);
        }

        try {
            $parentModel = new $modelClass;
            
            if (! method_exists($parentModel, $relationName)) {
                return $this->getFallbackLookupField($modelClass, $relationName);
            }

            $relation = $parentModel->$relationName();
            $relatedModel = $relation->getRelated();
            $table = $relatedModel->getTable();
            $primaryKey = $relatedModel->getKeyName();

            // Get fillable fields from related model
            $fillable = $relatedModel->getFillable();
            
            if (empty($fillable)) {
                return $primaryKey; // Fallback to primary key if no fillable
            }

            // Priority 1: First fillable field with a unique index (excluding primary key)
            $uniqueFields = $this->getUniqueFields($table);
            foreach ($fillable as $field) {
                if ($field !== $primaryKey && in_array($field, $uniqueFields, true)) {
                    return $field;
                }
            }

            // Priority 2: First fillable field
            return $fillable[0];
        } catch (\Exception $e) {
            return $this->getFallbackLookupField($modelClass, $relationName);
        }
    }

    /**
     * Get fallback lookup field when introspection fails.
     */
    private function getFallbackLookupField(string $modelClass, string $relationName): string
    {
        // Try to get the first fillable field from the related model
        try {
            if (class_exists($modelClass)) {
                $parentModel = new $modelClass;
                if (method_exists($parentModel, $relationName)) {
                    $relation = $parentModel->$relationName();
                    $relatedModel = $relation->getRelated();
                    $fillable = $relatedModel->getFillable();
                    if (! empty($fillable)) {
                        return $fillable[0];
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }
        
        return 'id'; // Ultimate fallback
    }

    /**
     * Get all fields with unique indexes from a table.
     *
     * @return array<string> List of field names with unique indexes
     */
    private function getUniqueFields(string $table): array
    {
        try {
            $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Non_unique = 0");
            return array_unique(array_column($indexes, 'Column_name'));
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Build ColumnMapping from components.
     */
    private function buildColumnMapping(
        string $sourceColumn,
        string $targetPath,
        array $transforms,
        string $modelClass,
        ?array $relationLookup
    ): ColumnMapping {
        $validationRule = $this->suggestValidationRule($modelClass, $targetPath);

        return new ColumnMapping(
            sourceColumn: $sourceColumn,
            targetPath: $targetPath,
            transforms: $transforms,
            validationRule: $validationRule,
            relationLookup: $relationLookup
        );
    }

    /**
     * Suggest transforms based on column metadata
     *
     * @return array<string>
     */
    /**
     * Suggest transforms for a column based on source type and target model field type.
     *
     * @param  ColumnMetadata|null  $columnMeta  Source column metadata
     * @param  string  $modelClass  Target model class
     * @param  string  $targetPath  Target field path (e.g., 'is_service' or 'category.name')
     * @return array<string> Suggested transform specifications
     */
    private function suggestTransforms(?ColumnMetadata $columnMeta, string $modelClass, string $targetPath): array
    {
        if ($columnMeta === null) {
            return ['trim'];
        }

        $transforms = ['trim'];

        // Check if target is relation.* (full array mapping) and source is JSON array
        if (str_ends_with($targetPath, '.*')) {
            // Check if column contains JSON array data
            if ($this->isArrayColumn($columnMeta)) {
                // Add json_decode transform for array relations
                $transforms[] = 'json_decode';
            }
        }

        // Determine target field cast type from model
        $targetCastType = $this->modelCastService->getCastType($modelClass, $targetPath);

        // Add type-specific transforms
        match ($columnMeta->type) {
            ColumnType::Email => $transforms[] = 'lower', // Already has trim
            default => null,
        };

        // Auto-add cast transform if target requires it and source type matches
        if ($targetCastType !== null) {
            $sourceCastType = $columnMeta->type->toCastType();

            // If source and target cast types match, add cast transform
            if ($sourceCastType === $targetCastType) {
                $transforms[] = "cast:{$targetCastType}";
            }

            // If source is string but target is date, suggest parse_date (interactive)
            if ($targetCastType === 'date' && $columnMeta->type === ColumnType::String) {
                $transforms[] = 'parse_date:';
            }
        } else {
            // If source type is detected as numeric/date/bool but no target cast, suggest cast anyway
            $castType = $columnMeta->type->toCastType();
            if ($castType !== null) {
                $transforms[] = "cast:{$castType}";
            }
        }

        return array_unique($transforms);
    }

    /**
     * Check if column metadata indicates an array/JSON column.
     */
    private function isArrayColumn(?ColumnMetadata $columnMeta): bool
    {
        if ($columnMeta === null || empty($columnMeta->examples)) {
            return false;
        }

        foreach ($columnMeta->examples as $example) {
            // Check if example is already an array (from JSON parsing)
            if (is_array($example)) {
                return true;
            }

            // Check if example is a string that looks like JSON array/object
            if (! is_string($example)) {
                continue;
            }

            $trimmed = trim($example);
            if (! (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{'))) {
                continue;
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Suggest validation rule based on model and target path
     */
    private function suggestValidationRule(string $modelClass, string $targetPath): ?string
    {
        if (! class_exists($modelClass)) {
            return null;
        }

        $model = new $modelClass;

        // Check if model has rules() method
        if (method_exists($model, 'rules')) {
            $rules = $model->rules();
            if (isset($rules[$targetPath])) {
                return $rules[$targetPath];
            }
        }

        // Default rules based on path
        if (str_contains($targetPath, '.')) {
            // Nested relation - nullable by default
            return 'nullable|string';
        }

        // Default: nullable string (agnostic - no assumptions about field names)
        return 'nullable|string';
    }
}
