<?php

namespace InFlow\Services\Mapping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use InFlow\Mappings\MappingBuilder;
use InFlow\Services\File\ModelSelectionService;
use InFlow\ValueObjects\MappingDefinition;
use InFlow\ValueObjects\ModelMapping;
use InFlow\ValueObjects\SourceSchema;

/**
 * Service for validating mappings before execution.
 *
 * Handles the business logic of analyzing model constraints, identifying missing
 * required fields, and suggesting auto-mapping solutions. Presentation logic
 * (output, prompts) is handled by the caller.
 */
readonly class MappingValidationService
{
    public function __construct(
        private ModelSelectionService $modelSelectionService
    ) {}

    /**
     * Extract mapped columns from mapping definition.
     *
     * Returns direct model attributes and relation fields grouped by relation.
     *
     * @param  MappingDefinition  $mapping  The mapping definition
     * @return array{main: array<string>, relations: array<string, array<string>>, relation_meta: array<string, array{has_non_optional_fields: bool, all_fields_optional: bool}>} Mapped columns grouped by main model and relations
     */
    public function extractMappedColumns(MappingDefinition $mapping): array
    {
        $mappedColumns = [];
        $relationColumns = [];
        $relationMeta = [];

        foreach ($mapping->mappings as $modelMapping) {
            foreach ($modelMapping->columns as $columnMapping) {
                $pathParts = explode('.', $columnMapping->targetPath);
                if (count($pathParts) === 1) {
                    // Direct model attribute
                    $mappedColumns[] = $columnMapping->targetPath;
                } else {
                    // Relation field (e.g., "mainImages.thumbnail_url")
                    $relationName = $pathParts[0];
                    $fieldName = $pathParts[1];

                    if (! isset($relationColumns[$relationName])) {
                        $relationColumns[$relationName] = [];
                    }

                    // Handle full array mapping (relation.*)
                    if ($fieldName === '*') {
                        // Mark as full array - all fields come from source
                        $relationMeta[$relationName] = [
                            'has_non_optional_fields' => true,
                            'all_fields_optional' => false,
                            'is_full_array' => true, // Skip individual field validation
                        ];
                        $relationColumns[$relationName][] = '*';

                        continue;
                    }

                    $relationColumns[$relationName][] = $fieldName;

                    $isOptional = str_starts_with($fieldName, '?');
                    if (! isset($relationMeta[$relationName])) {
                        $relationMeta[$relationName] = [
                            'has_non_optional_fields' => false,
                            'all_fields_optional' => true,
                            'is_full_array' => false,
                            'create_if_missing' => false,
                        ];
                    }

                    if (! $isOptional) {
                        $relationMeta[$relationName]['has_non_optional_fields'] = true;
                        $relationMeta[$relationName]['all_fields_optional'] = false;
                    }

                    // Extract create_if_missing from relationLookup
                    if ($columnMapping->relationLookup !== null && ($columnMapping->relationLookup['create_if_missing'] ?? false)) {
                        $relationMeta[$relationName]['create_if_missing'] = true;
                    }
                }
            }
        }

        return [
            'main' => array_unique($mappedColumns),
            'relations' => array_map('array_unique', $relationColumns),
            'relation_meta' => $relationMeta,
        ];
    }

    /**
     * Analyze model constraints to identify required fields.
     *
     * @param  string  $modelClass  The model class name
     * @param  array<string>  $fillable  Fillable attributes
     * @param  array<string>  $guarded  Guarded attributes
     * @param  array<string, string>  $validationRules  Validation rules
     * @return array{required_fields: array<string>, field_info: array<string, array{type: string, default: mixed|null, nullable: bool}>, fillable: array<string>, guarded: array<string>, validation_rules: array<string, string>}
     */
    public function analyzeModelConstraints(string $modelClass, array $fillable, array $guarded, array $validationRules): array
    {
        $requiredFields = [];
        $fieldInfo = [];

        try {
            $model = new $modelClass;
            $table = $model->getTable();

            // Get database schema information
            $columns = DB::select("SHOW COLUMNS FROM `{$table}`");

            foreach ($columns as $column) {
                $columnName = $column->Field;

                // Check if field is in fillable or not guarded
                $isFillable = in_array($columnName, $fillable, true);
                $isGuarded = ! empty($guarded) && ($guarded === ['*'] || in_array($columnName, $guarded, true));

                // Skip if guarded (unless explicitly fillable)
                if ($isGuarded && ! $isFillable) {
                    continue;
                }

                // Check if field is nullable
                $isNullable = $column->Null === 'YES';

                // Check if field has default value
                $hasDefault = $column->Default !== null;

                // Store field information
                $fieldInfo[$columnName] = [
                    'type' => $column->Type ?? 'varchar',
                    'default' => $column->Default,
                    'nullable' => $isNullable,
                ];

                // Required if: not nullable AND no default value AND not auto-increment
                $isAutoIncrement = str_contains($column->Extra ?? '', 'auto_increment');

                if (! $isNullable && ! $hasDefault && ! $isAutoIncrement) {
                    $requiredFields[] = $columnName;
                }
            }
        } catch (\Exception $e) {
            // If we can't analyze the schema, use fillable as fallback
            \inflow_report($e, 'warning', ['operation' => 'analyzeModelConstraints', 'model' => $modelClass]);
            $requiredFields = $fillable;
        }

        return [
            'required_fields' => array_unique($requiredFields),
            'field_info' => $fieldInfo,
            'fillable' => $fillable,
            'guarded' => $guarded,
            'validation_rules' => $validationRules,
        ];
    }

    /**
     * Identify missing required fields.
     *
     * @param  array<string>  $requiredFields  Required fields from model analysis
     * @param  array<string>  $mappedColumns  Already mapped columns
     * @param  array<string, string>  $validationRules  Validation rules
     * @param  array<string>  $mappedRelations  Names of relations that are being mapped (FK will be auto-resolved)
     * @return array<string> Array of missing required field names
     */
    public function identifyMissingFields(array $requiredFields, array $mappedColumns, array $validationRules, array $mappedRelations = []): array
    {
        // Identify required fields that are not mapped
        $missingFields = array_diff($requiredFields, $mappedColumns);

        // Exclude FK fields for mapped relations (they will be auto-resolved)
        // Convention: relation "author" => FK "author_id"
        $relationForeignKeys = array_map(fn ($rel) => $rel.'_id', $mappedRelations);
        $missingFields = array_diff($missingFields, $relationForeignKeys);

        // Also check for fields with validation rules that require values
        $requiredByRules = [];
        foreach ($validationRules as $field => $rule) {
            if (str_contains($rule, 'required') && ! in_array($field, $mappedColumns, true)) {
                // Skip FK fields for mapped relations
                if (in_array($field, $relationForeignKeys, true)) {
                    continue;
                }
                $requiredByRules[] = $field;
            }
        }

        return array_unique(array_merge($missingFields, $requiredByRules));
    }

    /**
     * Analyze relation constraints to identify required fields for relations.
     *
     * @param  string  $modelClass  The main model class
     * @param  array<string, array<string>>  $relationColumns  Mapped columns grouped by relation name
     * @param  array<string, array{has_non_optional_fields: bool, all_fields_optional: bool}>  $relationMeta  Relation metadata (optional vs non-optional fields)
     * @return array{required: array<string, array<string>>, conditional: array<string, array<string>>} Missing required fields grouped by relation name
     */
    public function analyzeRelationConstraints(string $modelClass, array $relationColumns, array $relationMeta = []): array
    {
        $missingRequired = [];
        $missingConditional = [];

        try {
            $model = new $modelClass;
            $relations = $this->modelSelectionService->getModelRelations($modelClass);

            foreach ($relationColumns as $relationName => $mappedFields) {
                if (! isset($relations[$relationName])) {
                    continue;
                }

                // Skip full array mappings (relation.*) - all fields come from source array
                $meta = $relationMeta[$relationName] ?? null;
                if ($meta !== null && ($meta['is_full_array'] ?? false) === true) {
                    continue;
                }

                // For BelongsTo/BelongsToMany relations:
                // - Skip validation if create_if_missing is FALSE (we're only looking up)
                // - Validate if create_if_missing is TRUE (we might create new records)
                if ($this->isBelongsToRelation($model, $relationName)) {
                    $createIfMissing = $meta !== null && ($meta['create_if_missing'] ?? false) === true;
                    if (! $createIfMissing) {
                        continue;
                    }
                }

                $relatedModelClass = $relations[$relationName];
                $relatedModel = new $relatedModelClass;
                $relatedFillable = $relatedModel->getFillable();
                $relatedGuarded = $relatedModel->getGuarded();

                // Analyze required fields for the related model
                $relatedRequiredFields = $this->analyzeModelConstraints(
                    $relatedModelClass,
                    $relatedFillable,
                    $relatedGuarded,
                    []
                )['required_fields'];

                // For HasMany/HasOne relations, exclude the parent's foreign key
                // because Laravel sets it automatically when creating through the relation
                if ($this->isHasRelation($model, $relationName)) {
                    $foreignKey = $this->getRelationForeignKey($model, $relationName);
                    if ($foreignKey !== null) {
                        $relatedRequiredFields = array_filter(
                            $relatedRequiredFields,
                            fn ($field) => $field !== $foreignKey
                        );
                    }
                }

                // Find missing required fields
                $missing = array_diff($relatedRequiredFields, $mappedFields);
                if (! empty($missing)) {
                    $meta = $relationMeta[$relationName] ?? null;
                    $isConditional = $meta !== null && ($meta['all_fields_optional'] ?? false) === true;

                    if ($isConditional) {
                        $missingConditional[$relationName] = array_values($missing);
                    } else {
                        $missingRequired[$relationName] = array_values($missing);
                    }
                }
            }
        } catch (\Exception $e) {
            \inflow_report($e, 'warning', [
                'operation' => 'analyzeRelationConstraints',
                'model' => $modelClass,
            ]);
        }

        return [
            'required' => $missingRequired,
            'conditional' => $missingConditional,
        ];
    }

    /**
     * Check if a relation is a BelongsTo (lookup-style, not create-style).
     *
     * @param  Model  $model  The model instance
     * @param  string  $relationName  The relation method name
     * @return bool True if it's a BelongsTo relation
     */
    private function isBelongsToRelation(Model $model, string $relationName): bool
    {
        try {
            $relation = $model->$relationName();

            return $relation instanceof BelongsTo
                || $relation instanceof BelongsToMany;
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', ['operation' => 'isBelongsToRelation', 'relation' => $relationName]);

            return false;
        }
    }

    /**
     * Check if a relation is a HasOne or HasMany relation.
     */
    private function isHasRelation(Model $model, string $relationName): bool
    {
        try {
            $relation = $model->$relationName();

            return $relation instanceof HasOne
                || $relation instanceof HasMany;
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', ['operation' => 'isHasRelation', 'relation' => $relationName]);

            return false;
        }
    }

    /**
     * Get the foreign key for a HasOne/HasMany relation.
     */
    private function getRelationForeignKey(Model $model, string $relationName): ?string
    {
        try {
            $relation = $model->$relationName();

            if ($relation instanceof HasOne
                || $relation instanceof HasMany) {
                return $relation->getForeignKeyName();
            }

            return null;
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', ['operation' => 'getRelationForeignKey', 'relation' => $relationName]);

            return null;
        }
    }

    /**
     * Get field information for display.
     *
     * @param  string  $field  The field name
     * @param  array<string>  $requiredFields  Required fields from model analysis
     * @param  array<string, string>  $validationRules  Validation rules
     * @param  array<string>  $fillable  Fillable attributes
     * @param  array<string>  $guarded  Guarded attributes
     * @return array<string> Array of field information strings
     */
    public function getFieldInfo(string $field, array $requiredFields, array $validationRules, array $fillable, array $guarded): array
    {
        $info = [];

        if (in_array($field, $requiredFields, true)) {
            $info[] = 'required by DB';
        }

        if (isset($validationRules[$field]) && str_contains($validationRules[$field], 'required')) {
            $info[] = 'required by validation';
        }

        if (in_array($field, $fillable, true)) {
            $info[] = 'fillable';
        } elseif (! in_array($field, $guarded, true)) {
            $info[] = 'not guarded';
        }

        return $info;
    }

    /**
     * Format missing field line for display.
     *
     * @param  string  $field  The field name
     * @param  array<string>  $info  Field information strings
     * @return string Formatted line
     */
    public function formatMissingFieldLine(string $field, array $info): string
    {
        $infoStr = ! empty($info) ? ' <fg=gray>('.implode(', ', $info).')</>' : '';

        return "    <fg=red>•</> <fg=yellow>{$field}</>{$infoStr}";
    }

    /**
     * Suggest auto-mapping for missing required fields.
     *
     * @param  array<string>  $missingFields  Missing field names
     * @param  SourceSchema  $sourceSchema  The source schema
     * @return array<string, array{source: string, confidence: float}> Suggestions array keyed by field name
     */
    public function suggestAutoMapping(array $missingFields, SourceSchema $sourceSchema): array
    {
        $suggestions = [];
        $availableColumns = $sourceSchema->getColumnNames();

        foreach ($missingFields as $field) {
            // Try exact match first
            if (in_array($field, $availableColumns, true)) {
                $suggestions[$field] = [
                    'source' => $field,
                    'confidence' => 1.0,
                ];

                continue;
            }

            // Try case-insensitive match
            $lowerField = strtolower($field);
            foreach ($availableColumns as $column) {
                if (strtolower($column) === $lowerField) {
                    $suggestions[$field] = [
                        'source' => $column,
                        'confidence' => 0.9,
                    ];
                    break;
                }
            }

            // If no match found, try partial match (e.g., "user_name" matches "name")
            if (! isset($suggestions[$field])) {
                foreach ($availableColumns as $column) {
                    if (str_contains(strtolower($column), $lowerField) || str_contains($lowerField, strtolower($column))) {
                        $suggestions[$field] = [
                            'source' => $column,
                            'confidence' => 0.5,
                        ];
                        break;
                    }
                }
            }
        }

        return $suggestions;
    }

    /**
     * Apply auto-mapping suggestions to mapping definition.
     *
     * @param  MappingDefinition  $mapping  The original mapping
     * @param  array<string, array{source: string, confidence: float}>  $suggestions  Auto-mapping suggestions
     * @param  SourceSchema  $sourceSchema  The source schema
     * @param  string  $modelClass  The model class name
     * @return MappingDefinition|null Updated mapping, or null if model class is invalid
     */
    public function applyAutoMapping(MappingDefinition $mapping, array $suggestions, SourceSchema $sourceSchema, string $modelClass): ?MappingDefinition
    {
        $modelMappingBuilder = MappingBuilder::for($modelClass);

        // Add existing mappings
        foreach ($mapping->mappings as $modelMapping) {
            foreach ($modelMapping->columns as $columnMapping) {
                $modelMappingBuilder->map(
                    sourceColumn: $columnMapping->sourceColumn,
                    targetPath: $columnMapping->targetPath,
                    transforms: $columnMapping->transforms,
                    default: $columnMapping->default,
                    validationRule: $columnMapping->validationRule,
                    relationLookup: $columnMapping->relationLookup
                );
            }
        }

        // Add auto-mapped fields (only if not already mapped)
        foreach ($suggestions as $field => $suggestion) {
            // Check if field is already mapped (to avoid overwriting existing mappings)
            $alreadyMapped = false;
            foreach ($mapping->mappings as $modelMapping) {
                foreach ($modelMapping->columns as $columnMapping) {
                    if ($columnMapping->targetPath === $field) {
                        $alreadyMapped = true;
                        break 2;
                    }
                }
            }

            if (! $alreadyMapped) {
                $sourceColumn = $suggestion['source'];
                $modelMappingBuilder->map($sourceColumn, $field);
            }
        }

        // Build new mapping definition
        $newModelMapping = $modelMappingBuilder->build();

        // Preserve options from original mapping
        $originalOptions = [];
        if (! empty($mapping->mappings)) {
            $originalOptions = $mapping->mappings[0]->options ?? [];
        }

        // Create new ModelMapping with preserved options
        $finalModelMapping = new ModelMapping(
            modelClass: $newModelMapping->modelClass,
            columns: $newModelMapping->columns,
            options: $originalOptions
        );

        return new MappingDefinition(
            mappings: [$finalModelMapping],
            name: $mapping->name,
            description: $mapping->description,
            sourceSchema: $mapping->sourceSchema ?? $sourceSchema
        );
    }

    /**
     * Apply field handlers to mapping definition.
     *
     * @param  MappingDefinition  $mapping  The original mapping
     * @param  array<string, array{action: string, value?: mixed, source?: string}>  $fieldHandlers  Field handlers
     * @param  SourceSchema|null  $sourceSchema  The source schema (fallback if mapping doesn't have one)
     * @param  string  $modelClass  The model class name
     * @return MappingDefinition|null Updated mapping, or null if model class is invalid
     */
    public function applyFieldHandlers(MappingDefinition $mapping, array $fieldHandlers, ?SourceSchema $sourceSchema, string $modelClass): ?MappingDefinition
    {
        $modelMappingBuilder = MappingBuilder::for($modelClass);

        // Add existing mappings
        foreach ($mapping->mappings as $modelMapping) {
            foreach ($modelMapping->columns as $columnMapping) {
                $modelMappingBuilder->map(
                    sourceColumn: $columnMapping->sourceColumn,
                    targetPath: $columnMapping->targetPath,
                    transforms: $columnMapping->transforms,
                    default: $columnMapping->default,
                    validationRule: $columnMapping->validationRule,
                    relationLookup: $columnMapping->relationLookup
                );
            }
        }

        // Apply handlers for missing fields
        foreach ($fieldHandlers as $field => $handler) {
            switch ($handler['action']) {
                case 'default':
                    // Create a virtual mapping with default value
                    $virtualSource = '__default_'.$field;
                    $modelMappingBuilder->map(
                        sourceColumn: $virtualSource,
                        targetPath: $field,
                        transforms: null,
                        default: $handler['value'] ?? null
                    );
                    break;

                case 'map':
                    $sourceColumn = $handler['source'] ?? null;
                    if ($sourceColumn !== null) {
                        $modelMappingBuilder->map($sourceColumn, $field);
                    }
                    break;

                case 'transform':
                    // Generate field from another mapped field using a transform
                    $sourceField = $handler['source_field'] ?? null;
                    $transform = $handler['transform'] ?? 'slugify';
                    if ($sourceField !== null) {
                        // Find the source column that maps to the source field
                        $sourceColumn = $this->findSourceColumnForTarget($mapping, $sourceField);
                        if ($sourceColumn !== null) {
                            $modelMappingBuilder->map(
                                sourceColumn: $sourceColumn,
                                targetPath: $field,
                                transforms: [$transform]
                            );
                        }
                    }
                    break;

                case 'skip':
                    // Add mapping with nullable validation to allow skipping
                    // This prevents validation errors when the field is required but not provided
                    $virtualSource = '__skip_'.$field;
                    $modelMappingBuilder->map(
                        sourceColumn: $virtualSource,
                        targetPath: $field,
                        transforms: null,
                        default: null,
                        validationRule: 'nullable'
                    );
                    break;
            }
        }

        // Build new mapping definition
        $newModelMapping = $modelMappingBuilder->build();

        return new MappingDefinition(
            mappings: [$newModelMapping],
            name: $mapping->name,
            description: $mapping->description,
            sourceSchema: $mapping->sourceSchema ?? $sourceSchema
        );
    }

    /**
     * Find the source column that maps to a specific target path.
     *
     * @param  MappingDefinition  $mapping  The mapping definition
     * @param  string  $targetPath  The target path to find (e.g., "tags.name")
     * @return string|null The source column name or null if not found
     */
    private function findSourceColumnForTarget(MappingDefinition $mapping, string $targetPath): ?string
    {
        foreach ($mapping->mappings as $modelMapping) {
            foreach ($modelMapping->columns as $columnMapping) {
                // Clean the target path (remove + suffix)
                $cleanTarget = rtrim($columnMapping->targetPath, '+');
                if ($cleanTarget === $targetPath) {
                    return $columnMapping->sourceColumn;
                }
            }
        }

        return null;
    }

    /**
     * Preserve mapping options when updating mapping.
     *
     * @param  MappingDefinition  $updatedMapping  The updated mapping
     * @param  MappingDefinition  $originalMapping  The original mapping
     * @return MappingDefinition Mapping with preserved options
     */
    public function preserveMappingOptions(MappingDefinition $updatedMapping, MappingDefinition $originalMapping): MappingDefinition
    {
        $firstMapping = $updatedMapping->mappings[0] ?? null;
        $originalOptions = ! empty($originalMapping->mappings) ? ($originalMapping->mappings[0]->options ?? []) : [];

        if ($firstMapping !== null && empty($firstMapping->options) && ! empty($originalOptions)) {
            // Rebuild with preserved options
            $finalModelMapping = new ModelMapping(
                modelClass: $firstMapping->modelClass,
                columns: $firstMapping->columns,
                options: $originalOptions
            );

            return new MappingDefinition(
                mappings: [$finalModelMapping],
                name: $updatedMapping->name,
                description: $updatedMapping->description,
                sourceSchema: $updatedMapping->sourceSchema
            );
        }

        return $updatedMapping;
    }

    /**
     * Get validation rules from model if available.
     *
     * @param  string  $modelClass  The model class name
     * @return array<string, string> Validation rules array
     */
    public function getModelValidationRules(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        $model = new $modelClass;
        $rules = [];

        // Check if model has rules() method
        if (method_exists($model, 'rules')) {
            $modelRules = $model->rules();
            if (is_array($modelRules)) {
                $rules = $modelRules;
            }
        }

        // Check if model has validationRules() method (alternative)
        if (empty($rules) && method_exists($model, 'validationRules')) {
            $modelRules = $model->validationRules();
            if (is_array($modelRules)) {
                $rules = $modelRules;
            }
        }

        return $rules;
    }

    /**
     * Format model analysis summary for verbose display.
     *
     * @param  array<string>  $fillable  Fillable attributes
     * @param  array<string>  $guarded  Guarded attributes
     * @param  array<string, string>  $validationRules  Validation rules
     * @return array<string> Array of formatted lines
     */
    public function formatModelAnalysisSummary(array $fillable, array $guarded, array $validationRules): array
    {
        $lines = [
            '  <fg=cyan>Model Analysis:</>',
            '    Fillable: '.implode(', ', $fillable),
        ];

        if (! empty($guarded) && $guarded !== ['*']) {
            $lines[] = '    Guarded: '.implode(', ', $guarded);
        }

        if (! empty($validationRules)) {
            $lines[] = '    Validation rules: '.count($validationRules).' field(s)';
        }

        return $lines;
    }
}
