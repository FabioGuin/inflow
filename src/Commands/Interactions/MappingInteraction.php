<?php

namespace InFlow\Commands\Interactions;

use Illuminate\Console\Command;
use InFlow\Enums\Data\EloquentRelationType;
use InFlow\Services\File\ModelSelectionService;
use InFlow\Services\Loading\RelationTypeService;
use InFlow\ValueObjects\Data\ColumnMetadata;
use InFlow\ValueObjects\Data\SourceSchema;

/**
 * Handles interactive prompts for mapping creation.
 *
 * Separates interaction logic from business logic, following the same pattern
 * as TransformInteraction and other interaction classes.
 */
readonly class MappingInteraction
{
    public function __construct(
        private Command $command,
        private ModelSelectionService $modelSelectionService,
        private RelationTypeService $relationTypeService
    ) {}

    /**
     * Prompt user to select a model interactively.
     *
     * @return string|null Selected model class name, or null if cancelled/not available
     */
    public function promptModelSelection(): ?string
    {
        // Skip if quiet mode or no-interaction
        if ($this->isNonInteractive()) {
            return null;
        }

        $models = $this->modelSelectionService->getAllModelsInNamespace('App\\Models');

        if (empty($models)) {
            $this->command->warn('No models found in App\\Models namespace.');
            $this->command->line('Please provide model class as argument: php artisan inflow:make-mapping file.csv "App\\Models\\YourModel"');

            return null;
        }

        // Build options array for select prompt
        $options = [];
        foreach ($models as $modelClass) {
            $displayName = class_basename($modelClass);
            $options[$modelClass] = $displayName;
        }

        // Sort by display name
        asort($options);

        try {
            return $this->select('Select target model:', $options);
        } catch (\Laravel\Prompts\Exceptions\NonInteractiveValidationException $e) {
            $this->command->error('Cannot prompt for model selection in non-interactive mode.');
            $this->command->line('Please provide model class as argument: php artisan inflow:make-mapping file.csv "App\\Models\\YourModel"');

            return null;
        }
    }

    /**
     * Prompt user for output path interactively.
     *
     * @param  string  $defaultPath  Default path to suggest
     * @return string|null Selected output path, or null if cancelled
     */
    public function promptOutputPath(string $defaultPath = 'mappings/mapping.json'): ?string
    {
        if ($this->isNonInteractive()) {
            return $defaultPath;
        }

        try {
            return $this->ask('Output path for mapping file:', $defaultPath);
        } catch (\Laravel\Prompts\Exceptions\NonInteractiveValidationException $e) {
            return $defaultPath;
        }
    }

    /**
     * Prompt user to confirm overwrite of existing file.
     *
     * @param  string  $filePath  Path to the file that exists
     * @return bool True if user confirms overwrite, false otherwise
     */
    public function promptOverwriteConfirmation(string $filePath): bool
    {
        if ($this->isNonInteractive()) {
            return false;
        }

        try {
            return $this->confirm("Mapping file already exists: {$filePath}. Overwrite?", default: false);
        } catch (\Laravel\Prompts\Exceptions\NonInteractiveValidationException $e) {
            return false;
        }
    }

    /**
     * Prompt user to map source columns interactively.
     *
     * Supports:
     * - Multiple mappings from the same source column (e.g., JSON with multiple fields)
     * - Creating separate mappings for related models (e.g., Book with execution_order: 2)
     *
     * @param  SourceSchema  $sourceSchema  Source schema with columns
     * @param  string  $modelClass  Target model class
     * @return array{
     *     main: array<int, array{source: string, target: string, transforms: array<string>, default: mixed, validation_rule: string|null}>,
     *     related: array<string, array{model: string, execution_order: int, columns: array}>
     * } Main mappings and related model mappings
     */
    public function promptColumnMappings(SourceSchema $sourceSchema, string $modelClass): array
    {
        if ($this->isNonInteractive()) {
            return ['main' => [], 'related' => []];
        }

        $this->command->newLine();
        $this->command->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</fg=cyan>');
        $this->command->line('<fg=cyan>📋 Column Mapping</fg=cyan>');
        $this->command->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</fg=cyan>');
        $this->command->newLine();
        $this->command->line('<fg=gray>Map each source column to target fields. Follow Model-First philosophy: prefer model casts/mutators/rules.</fg=gray>');
        $this->command->line('<fg=gray>Target options are organized: first direct attributes, then direct relations, then nested relations.</fg=gray>');
        $this->command->newLine();

        // Get available targets (attributes + relations)
        $attributes = $this->modelSelectionService->getAllModelAttributes($modelClass);
        $relations = $this->modelSelectionService->getModelRelations($modelClass);

        // Build target options organized by category
        $targetOptions = $this->buildTargetOptions($attributes, $relations, $modelClass);
        $targetOptions['__skip__'] = '<fg=gray>Skip this column</fg=gray>';

        $mainMappings = [];
        $relatedMappings = []; // [modelClass => [model, execution_order, columns]]
        $totalColumns = count($sourceSchema->columns);
        $currentColumn = 0;

        foreach ($sourceSchema->columns as $sourceColumn) {
            $currentColumn++;
            // Show progress
            $this->command->newLine();
            $this->command->line('<fg=gray>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</fg=gray>');
            $this->command->line("<fg=gray>Column {$currentColumn}/{$totalColumns}</fg=gray>");
            $this->command->newLine();
            
            $columnMappings = $this->promptColumnMappingWithMultiSupport(
                $sourceColumn,
                $targetOptions,
                $modelClass,
                $relations
            );
            
            // Show summary for this column
            if (! empty($columnMappings)) {
                $this->showColumnMappingSummary($sourceColumn, $columnMappings);
            }

            // Separate main mappings from related model mappings
            foreach ($columnMappings as $mapping) {
                // Check for nested model mappings first (e.g., Tag from Book)
                if (isset($mapping['nested_model'])) {
                    $nestedModelClass = $mapping['nested_model'];
                    unset($mapping['nested_model']);
                    unset($mapping['nested_relation']);
                    unset($mapping['related_model']); // Remove parent relation model

                    if (! isset($relatedMappings[$nestedModelClass])) {
                        $relatedMappings[$nestedModelClass] = [
                            'model' => $nestedModelClass,
                            'execution_order' => 0, // Will be set later
                            'columns' => [],
                        ];
                    }

                    $relatedMappings[$nestedModelClass]['columns'][] = $mapping;
                } elseif (isset($mapping['related_model'])) {
                    // This is a mapping for a related model (e.g., Book from Author)
                    $relatedModelClass = $mapping['related_model'];
                    unset($mapping['related_model']);

                    if (! isset($relatedMappings[$relatedModelClass])) {
                        // Determine execution order (will be calculated later)
                        $relatedMappings[$relatedModelClass] = [
                            'model' => $relatedModelClass,
                            'execution_order' => 0, // Will be set later
                            'columns' => [],
                        ];
                    }

                    $relatedMappings[$relatedModelClass]['columns'][] = $mapping;
                } else {
                    // Main mapping
                    $mainMappings[] = $mapping;
                }
            }
        }

        return [
            'main' => $mainMappings,
            'related' => $relatedMappings,
        ];
    }

    /**
     * Show summary of mappings for a single column.
     */
    private function showColumnMappingSummary(ColumnMetadata $sourceColumn, array $mappings): void
    {
        $this->command->newLine();
        $this->command->line('  <fg=green>✓ Mapped column "'.$sourceColumn->name.'":</fg=green>');
        
        foreach ($mappings as $mapping) {
            $target = $mapping['target'];
            $transforms = ! empty($mapping['transforms']) ? ' ['.implode(', ', $mapping['transforms']).']' : '';
            $this->command->line('    <fg=gray>→</fg=gray> <fg=cyan>'.$target.'</fg=cyan>'.$transforms);
        }
    }

    /**
     * Show final summary of all mappings before saving.
     * 
     * @param  array<string, int>  $executionOrders  Map of model class => execution order
     */
    public function showFinalMappingSummary(array $mainMappings, array $relatedMappings, string $modelClass, array $executionOrders = []): void
    {
        $this->command->newLine();
        $this->command->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</fg=cyan>');
        $this->command->line('<fg=cyan>📊 Mapping Summary</fg=cyan>');
        $this->command->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</fg=cyan>');
        $this->command->newLine();
        
        // Main model mappings
        if (! empty($mainMappings)) {
            $mainOrder = $executionOrders[$modelClass] ?? 1;
            $this->command->line('<fg=yellow>📌 '.class_basename($modelClass).' (execution_order: '.$mainOrder.')</fg=yellow>');
            foreach ($mainMappings as $mapping) {
                $transforms = ! empty($mapping['transforms']) ? ' ['.implode(', ', $mapping['transforms']).']' : '';
                $this->command->line('  <fg=gray>•</fg=gray> <fg=cyan>'.$mapping['source'].'</fg=cyan> → <fg=green>'.$mapping['target'].'</fg=green>'.$transforms);
            }
            $this->command->newLine();
        }
        
        // Related model mappings
        if (! empty($relatedMappings)) {
            foreach ($relatedMappings as $relatedModelClass => $relatedMapping) {
                $execOrder = $relatedMapping['execution_order'] ?? ($executionOrders[$relatedModelClass] ?? '?');
                $this->command->line('<fg=yellow>📌 '.class_basename($relatedModelClass).' (execution_order: '.$execOrder.')</fg=yellow>');
                foreach ($relatedMapping['columns'] as $mapping) {
                    $transforms = ! empty($mapping['transforms']) ? ' ['.implode(', ', $mapping['transforms']).']' : '';
                    $this->command->line('  <fg=gray>•</fg=gray> <fg=cyan>'.$mapping['source'].'</fg=cyan> → <fg=green>'.$mapping['target'].'</fg=green>'.$transforms);
                }
                $this->command->newLine();
            }
        }
        
        $totalMappings = count($mainMappings) + array_sum(array_map(fn ($m) => count($m['columns']), $relatedMappings));
        $totalModels = 1 + count($relatedMappings);
        
        $this->command->line('<fg=green>✓ Total: '.$totalMappings.' column(s) mapped across '.$totalModels.' model(s)</fg=green>');
        $this->command->newLine();
    }

    /**
     * Prompt user to map a source column, supporting multiple mappings from the same column.
     *
     * @param  array<string, string>  $relations  Relation name => Related model class
     * @return array<int, array{source: string, target: string, transforms: array<string>, default: mixed, validation_rule: string|null, related_model?: string}>
     */
    private function promptColumnMappingWithMultiSupport(
        ColumnMetadata $sourceColumn,
        array $targetOptions,
        string $modelClass,
        array $relations
    ): array {
        $mappings = [];
        $continueMapping = true;

        while ($continueMapping) {
            if (empty($mappings)) {
                $this->command->newLine();
                $this->command->line('<fg=yellow>📥 Source Column:</fg=yellow> <fg=cyan;options=bold>'.$sourceColumn->name.'</fg=cyan;options=bold>');
            }

            // Show column info (only first time)
            if (empty($mappings)) {
                $examples = ! empty($sourceColumn->examples) ? implode(', ', array_slice($sourceColumn->examples, 0, 3)) : 'none';
                $this->command->line('  <fg=gray>Type:</fg=gray> '.$sourceColumn->type->value.' | <fg=gray>Examples:</fg=gray> '.$examples);

                // Warn if JSON type and suggest json_decode
                if ($sourceColumn->type->value === 'json') {
                    $this->command->line('  <fg=yellow>⚠️  JSON column detected. You\'ll need json_decode transform for relations (HasMany/BelongsToMany).</fg=yellow>');
                }
            } else {
                $this->command->line('  <fg=gray>Mapping additional fields from this column...</fg=gray>');
            }

            // Select target (filter out separators from actual selection)
            $selectableOptions = array_filter(
                $targetOptions,
                fn ($key) => ! str_starts_with($key, '__separator_'),
                ARRAY_FILTER_USE_KEY
            );
            
            $selectedTarget = $this->select('  Select target field:', $selectableOptions);

            if ($selectedTarget === null || $selectedTarget === '__skip__') {
                break;
            }

            if ($selectedTarget === '__map_more__') {
                // User wants to map more fields, continue loop
                continue;
            }

            // Check if target is a relation (HasMany/BelongsToMany)
            $isRelation = str_contains($selectedTarget, '.*');
            $relatedModelClass = null;

            if ($isRelation) {
                // Extract relation name and field from target (e.g., "books.*.title" -> "books", "title")
                [$relationName, $fieldName] = explode('.*.', $selectedTarget, 2);
                if (isset($relations[$relationName])) {
                    $relatedModelClass = $relations[$relationName];

                    // Ask if user wants to create a separate mapping for this related model
                    $createSeparateMapping = $this->promptCreateRelatedModelMapping($relationName, $relatedModelClass, $sourceColumn);

                    if ($createSeparateMapping) {
                        // Get available targets for related model (attributes + relations)
                        // When mapping from JSON, we can map both direct fields and nested relations
                        $relatedAttributes = $this->modelSelectionService->getAllModelAttributes($relatedModelClass);
                        $relatedRelations = $this->modelSelectionService->getModelRelations($relatedModelClass);
                        
                        // Filter system fields for this relation
                        try {
                            $relationType = $this->relationTypeService->getRelationType($modelClass, $relationName);
                        } catch (\Exception $e) {
                            $relationType = null;
                        }
                        
                        $relatedAttributes = $this->filterSystemFieldsForRelation(
                            $relatedAttributes,
                            $modelClass,
                            $relationName,
                            $relationType
                        );
                        
                        // Build options with attributes AND relations (for nested JSON like tags)
                        $relatedTargetOptions = $this->buildTargetOptions($relatedAttributes, $relatedRelations, $relatedModelClass);

                        // Map multiple fields from same source column to related model
                        $relatedMappings = $this->promptMultipleFieldsFromSameColumn(
                            $sourceColumn,
                            $relatedTargetOptions,
                            $relatedModelClass,
                            $relationName
                        );

                        // Add all related mappings with relation prefix in target
                        // These will be grouped into a separate mapping for the related model
                        foreach ($relatedMappings as $mapping) {
                            $target = $mapping['target'];
                            
                            // Check if target is a nested relation (e.g., "tags.*.name")
                            // Nested relations need their own separate mapping
                            if (str_contains($target, '.*.')) {
                                // Extract nested relation name (e.g., "tags.*.name" -> "tags")
                                [$nestedRelationName] = explode('.*.', $target, 2);
                                
                                // Check if this nested relation exists in the related model
                                if (isset($relatedRelations[$nestedRelationName])) {
                                    $nestedModelClass = $relatedRelations[$nestedRelationName];
                                    
                                    // This is a nested relation - create separate mapping for nested model
                                    // Target should be just "tags.*.name" (not "books.*.tags.*.name")
                                    // because Tag is a separate model with its own mapping
                                    // The source should still be the original column (books)
                                    // but the target is relative to the nested model (Tag)
                                    $mapping['nested_model'] = $nestedModelClass;
                                    $mapping['nested_relation'] = $nestedRelationName;
                                    // Keep target as is (e.g., "tags.*.name") - will be in Tag mapping
                                    // Don't add related_model - this will be handled separately
                                } else {
                                    // Not a valid nested relation, treat as direct attribute
                                    $mapping['target'] = "{$relationName}.*.{$target}";
                                    $mapping['related_model'] = $relatedModelClass;
                                }
                            } else {
                                // Direct attribute (e.g., title) - add relation prefix
                                $mapping['target'] = "{$relationName}.*.{$target}";
                                $mapping['related_model'] = $relatedModelClass;
                            }
                            
                            $mappings[] = $mapping;
                        }

                        // Don't continue mapping this column in main context - we've created separate mapping
                        $continueMapping = false;
                        break;
                    }
                }
            }

            // Ask for transforms (optional, following Model-First)
            $transforms = $this->promptTransforms($sourceColumn->name, $selectedTarget, $modelClass);

            // Ask for default (optional, only if needed)
            $default = $this->promptDefault($sourceColumn->name, $selectedTarget);

            // Ask for validation rule (optional, only if needed)
            $validationRule = $this->promptValidationRule($sourceColumn->name, $selectedTarget);

            $mapping = [
                'source' => $sourceColumn->name,
                'target' => $selectedTarget,
                'transforms' => $transforms,
                'default' => $default,
                'validation_rule' => $validationRule,
            ];

            if ($relatedModelClass !== null) {
                $mapping['related_model'] = $relatedModelClass;
            }

            $mappings[] = $mapping;
            
            // Show immediate feedback
            $this->command->line('');
            $transformsInfo = ! empty($transforms) ? ' <fg=gray>[transforms: '.implode(', ', $transforms).']</fg=gray>' : '';
            $this->command->line('  <fg=green>✓</fg=green> <fg=cyan>'.$sourceColumn->name.'</fg=cyan> → <fg=green>'.$selectedTarget.'</fg=green>'.$transformsInfo);

            // Ask if user wants to map more fields from this column
            // Only ask for JSON columns or if target is a relation (HasMany/BelongsToMany)
            $shouldAskForMore = $sourceColumn->type->value === 'json' || $isRelation;

            if ($shouldAskForMore) {
                $this->command->newLine();
                $continueMapping = $this->confirm('  Map another field from this column?', default: false);
            } else {
                // For simple columns, don't ask to map more (one field = one mapping)
                $continueMapping = false;
            }
        }

        return $mappings;
    }

    /**
     * Prompt user if they want to create a separate mapping for a related model.
     */
    private function promptCreateRelatedModelMapping(string $relationName, string $relatedModelClass, ColumnMetadata $sourceColumn): bool
    {
        $relatedModelName = class_basename($relatedModelClass);
        $this->command->newLine();
        $this->command->line("  <fg=cyan>📦 Relation detected: {$relationName} → {$relatedModelName}</fg=cyan>");
        
        if ($sourceColumn->type->value === 'json') {
            $this->command->line('  <fg=gray>The source column "'.$sourceColumn->name.'" contains JSON data.</fg=gray>');
            $this->command->line('  <fg=gray>You can create a separate mapping to map multiple JSON fields to '.$relatedModelName.' attributes.</fg=gray>');
            $this->command->line('  <fg=yellow>💡 Example: Map JSON fields "title", "isbn", "price" from "'.$sourceColumn->name.'" → '.$relatedModelName.' attributes</fg=yellow>');
        } else {
            $this->command->line('  <fg=gray>You can create a separate mapping for '.$relatedModelName.' to map multiple fields from this source column.</fg=gray>');
        }
        
        $this->command->line('  <fg=gray>This will create a mapping with execution_order: 2 (or higher) for '.$relatedModelName.'.</fg=gray>');

        return $this->confirm('  Create separate mapping for '.$relatedModelName.'?', default: true);
    }

    /**
     * Prompt user to map multiple fields from the same source column to a related model.
     *
     * Returns targets WITHOUT relation prefix (e.g., "title" not "books.*.title")
     * The prefix will be added later when creating the mapping.
     *
     * @return array<int, array{source: string, target: string, transforms: array<string>, default: mixed, validation_rule: string|null}>
     */
    private function promptMultipleFieldsFromSameColumn(
        ColumnMetadata $sourceColumn,
        array $targetOptions,
        string $relatedModelClass,
        string $relationName
    ): array {
        $mappings = [];
        $continueMapping = true;
        $mappedTargets = []; // Track already mapped targets to avoid duplicates

        $this->command->newLine();
        $this->command->line("  <fg=cyan>📦 Creating mapping for ".class_basename($relatedModelClass)." (from {$relationName})</fg=cyan>");
        $this->command->newLine();
        
        if ($sourceColumn->type->value === 'json') {
            $this->command->line('  <fg=yellow>📥 Source:</fg=yellow> Column <fg=cyan>"'.$sourceColumn->name.'"</fg=cyan> contains JSON array');
            $this->command->line('  <fg=yellow>📤 Target:</fg=yellow> Map JSON fields → '.class_basename($relatedModelClass).' attributes and relations');
            $this->command->line('  <fg=gray>Example: JSON field "title" → Book.title, or "tags.*.name" → Book.tags (nested)</fg=gray>');
            $this->command->line('  <fg=yellow>⚠️  Transform required: json_decode (will be added automatically)</fg=yellow>');
        } else {
            $this->command->line('  <fg=yellow>📥 Source:</fg=yellow> Column <fg=cyan>"'.$sourceColumn->name.'"</fg=cyan>');
            $this->command->line('  <fg=yellow>📤 Target:</fg=yellow> '.class_basename($relatedModelClass).' attributes and relations');
        }
        $this->command->newLine();

        while ($continueMapping) {
            // Filter out already mapped targets
            $availableOptions = array_filter(
                $targetOptions,
                fn ($label, $target) => ! in_array($target, $mappedTargets, true) && $target !== '__done__',
                ARRAY_FILTER_USE_BOTH
            );

            if (empty($availableOptions)) {
                $this->command->line('  <fg=gray>All available fields have been mapped.</fg=gray>');
                break;
            }

            // Add __done__ option if we have at least one mapping
            if (! empty($mappings)) {
                $availableOptions['__done__'] = '<fg=gray>Done - finish mapping for '.class_basename($relatedModelClass).'</fg=gray>';
            }

            // Build clearer prompt based on source type
            if ($sourceColumn->type->value === 'json') {
                $prompt = '  Select JSON field to map → '.class_basename($relatedModelClass).' attribute:';
            } else {
                $prompt = '  Select field to map:';
            }
            
            $selectedTarget = $this->select($prompt, $availableOptions);

            if ($selectedTarget === null || $selectedTarget === '__done__') {
                break;
            }

            // Extract clean target (remove relation prefix if present)
            // For direct attributes: "title" → "title"
            // For relations: "tags.*.name" → "tags.*.name" (keep full path)
            $cleanTarget = $selectedTarget;
            
            // If target contains relation (.*), keep it as is for nested relations
            // Otherwise it's a direct attribute
            if (str_contains($selectedTarget, '.*.')) {
                // This is a nested relation (e.g., tags.*.name)
                // Keep the full path but remove any prefix from buildTargetOptions
                // buildTargetOptions already returns "tags.*.name" format
                $cleanTarget = $selectedTarget;
            } else {
                // Direct attribute
                $cleanTarget = $selectedTarget;
            }

            // For JSON columns, automatically add json_decode transform
            $transforms = [];
            if ($sourceColumn->type->value === 'json') {
                $transforms = ['json_decode'];
                $this->command->line('  <fg=green>✓ Transform added: json_decode (required for JSON columns)</fg=green>');
            } else {
                // Ask for transforms (optional, following Model-First)
                $transforms = $this->promptTransforms($sourceColumn->name, $cleanTarget, $relatedModelClass);
            }

            // Ask for default (optional, only if needed)
            $default = $this->promptDefault($sourceColumn->name, $cleanTarget);

            // Ask for validation rule (optional, only if needed)
            $validationRule = $this->promptValidationRule($sourceColumn->name, $cleanTarget);

            $mappings[] = [
                'source' => $sourceColumn->name,
                'target' => $cleanTarget, // Store without relation prefix
                'transforms' => $transforms,
                'default' => $default,
                'validation_rule' => $validationRule,
            ];

            $mappedTargets[] = $selectedTarget; // Track to filter it out

            // Show what was mapped
            if ($sourceColumn->type->value === 'json') {
                $this->command->line('  <fg=green>✓ Mapped: JSON field "'.$cleanTarget.'" in "'.$sourceColumn->name.'" → '.class_basename($relatedModelClass).'.'.$cleanTarget.'</fg=green>');
            } else {
                $this->command->line('  <fg=green>✓ Mapped: '.$sourceColumn->name.' → '.class_basename($relatedModelClass).'.'.$cleanTarget.'</fg=green>');
            }

            // Ask if user wants to map more fields (default: yes for JSON, no otherwise)
            $defaultContinue = $sourceColumn->type->value === 'json';
            $continueMapping = $this->confirm('  Map another field?', default: $defaultContinue);
        }

        if (! empty($mappings)) {
            $this->command->line('  <fg=green>✓ Mapped '.count($mappings).' field(s) for '.class_basename($relatedModelClass).'</fg=green>');
        }

        return $mappings;
    }

    /**
     * Build target options from attributes and relations, organized by category.
     *
     * Options are organized in this order:
     * 1. Direct attributes (e.g., "name", "email")
     * 2. Direct relations - HasOne/BelongsTo (e.g., "profile.bio")
     * 3. Nested relations - HasMany/BelongsToMany (e.g., "books.*.title")
     *
     * @param  array<string>  $attributes  Model attributes
     * @param  array<string, string>  $relations  Relation name => Related model class
     * @param  string  $modelClass  Current model class to determine relation types
     * @return array<string, string> Target path => Display label
     */
    private function buildTargetOptions(array $attributes, array $relations, string $modelClass): array
    {
        $options = [];

        // Phase 1: Direct attributes
        foreach ($attributes as $attribute) {
            $options[$attribute] = "<fg=green>{$attribute}</fg=green> <fg=gray>(attribute)</fg=gray>";
        }

        // Phase 2: Direct relations (HasOne, BelongsTo)
        $directRelations = [];
        foreach ($relations as $relationName => $relatedModelClass) {
            try {
                $relationType = $this->relationTypeService->getRelationType($modelClass, $relationName);
            } catch (\Exception $e) {
                continue;
            }

            if ($relationType === EloquentRelationType::HasOne || $relationType === EloquentRelationType::BelongsTo) {
                $directRelations[$relationName] = [
                    'model' => $relatedModelClass,
                    'type' => $relationType,
                ];
            }
        }

        if (! empty($directRelations)) {
            // Add separator for direct relations
            $options['__separator_direct__'] = '<fg=yellow>━━ Direct Relations (HasOne/BelongsTo) ━━</fg=yellow>';
            
            foreach ($directRelations as $relationName => $info) {
                $relatedModelName = class_basename($info['model']);
                $relatedAttributes = $this->modelSelectionService->getAllModelAttributes($info['model']);
                
                $relatedAttributes = $this->filterSystemFieldsForRelation(
                    $relatedAttributes,
                    $modelClass,
                    $relationName,
                    $info['type']
                );

                $relationTypeLabel = $info['type'] === EloquentRelationType::HasOne ? 'HasOne' : 'BelongsTo';
                
                foreach (array_slice($relatedAttributes, 0, 10) as $attr) {
                    $options["{$relationName}.{$attr}"] = "<fg=cyan>{$relationName}.{$attr}</fg=cyan> <fg=gray>({$relatedModelName} - {$relationTypeLabel})</fg=gray>";
                }
            }
        }

        // Phase 3: Nested relations (HasMany, BelongsToMany)
        $nestedRelations = [];
        foreach ($relations as $relationName => $relatedModelClass) {
            try {
                $relationType = $this->relationTypeService->getRelationType($modelClass, $relationName);
            } catch (\Exception $e) {
                continue;
            }

            if ($relationType === EloquentRelationType::HasMany || $relationType === EloquentRelationType::BelongsToMany) {
                $nestedRelations[$relationName] = [
                    'model' => $relatedModelClass,
                    'type' => $relationType,
                ];
            }
        }

        if (! empty($nestedRelations)) {
            // Add separator for nested relations
            $options['__separator_nested__'] = '<fg=yellow>━━ Nested Relations (HasMany/BelongsToMany) ━━</fg=yellow>';
            
            foreach ($nestedRelations as $relationName => $info) {
                $relatedModelName = class_basename($info['model']);
                $relatedAttributes = $this->modelSelectionService->getAllModelAttributes($info['model']);
                
                $relatedAttributes = $this->filterSystemFieldsForRelation(
                    $relatedAttributes,
                    $modelClass,
                    $relationName,
                    $info['type']
                );

                $relationTypeLabel = $info['type'] === EloquentRelationType::HasMany ? 'HasMany' : 'BelongsToMany';
                
                foreach (array_slice($relatedAttributes, 0, 10) as $attr) {
                    $options["{$relationName}.*.{$attr}"] = "<fg=cyan>{$relationName}.*.{$attr}</fg=cyan> <fg=gray>({$relatedModelName} - {$relationTypeLabel})</fg=gray>";
                }
            }
        }

        return $options;
    }

    /**
     * Filter out system fields that are auto-managed by Eloquent for a specific relation.
     *
     * Excludes:
     * - Foreign keys that point back to the parent model (for HasMany/HasOne)
     * - Primary keys (id) - auto-generated
     * - Timestamps (created_at, updated_at, deleted_at) - auto-managed
     *
     * @param  array<string>  $attributes  Attributes to filter (from related model)
     * @param  string  $parentModelClass  Parent model class
     * @param  string  $relationName  Relation name
     * @param  EloquentRelationType|null  $relationType  Type of relation
     * @return array<string> Filtered attributes
     */
    private function filterSystemFieldsForRelation(
        array $attributes,
        string $parentModelClass,
        string $relationName,
        ?EloquentRelationType $relationType
    ): array {
        $filtered = [];

        // Standard system fields to always exclude
        $systemFields = ['id', 'created_at', 'updated_at', 'deleted_at'];

        // For HasMany/HasOne: exclude foreign key on related model that points to parent
        // Example: Book has author_id (foreign key) pointing to Author
        // When mapping books.*, we should exclude author_id because it's set automatically
        if ($relationType === EloquentRelationType::HasMany || $relationType === EloquentRelationType::HasOne) {
            try {
                $parentModel = new $parentModelClass;
                if (method_exists($parentModel, $relationName)) {
                    $relation = $parentModel->$relationName();
                    if (method_exists($relation, 'getForeignKeyName')) {
                        $foreignKey = $relation->getForeignKeyName();
                        $systemFields[] = $foreignKey;
                    }
                }
            } catch (\Exception $e) {
                // If we can't detect, continue without filtering foreign key
            }
        }

        // For BelongsTo: the foreign key is on the parent model, not the related model
        // So we don't need to filter it from related model attributes
        // Example: Book belongsTo Author, Book has author_id, Author doesn't have author_id

        // For BelongsToMany: foreign keys are in pivot table, not on related model
        // So we don't need to filter them

        // Filter attributes
        foreach ($attributes as $attr) {
            // Skip system fields
            if (in_array($attr, $systemFields, true)) {
                continue;
            }

            $filtered[] = $attr;
        }

        return $filtered;
    }

    /**
     * Prompt for transforms (optional, following Model-First philosophy).
     *
     * @return array<string>
     */
    private function promptTransforms(string $sourceColumn, string $target, string $modelClass): array
    {
        $this->command->line('');
        $this->command->line('  <fg=gray>Transforms (optional): Use only for ETL-specific cleaning. Prefer model casts/mutators.</fg=gray>');
        
        // Suggest json_decode if source column name suggests JSON or target uses wildcard
        $suggestedTransform = '';
        if (str_contains($sourceColumn, 'json') || str_contains($target, '.*')) {
            $suggestedTransform = 'json_decode';
            $this->command->line('  <fg=yellow>💡 Suggested: json_decode (for JSON arrays/relations)</fg=yellow>');
        } else {
            $this->command->line('  <fg=gray>Common: trim, json_decode. Leave empty if model handles transformation.</fg=gray>');
        }

        $transformsInput = $this->ask('  Transforms (comma-separated, or empty):', $suggestedTransform);

        if (empty(trim($transformsInput))) {
            // Default to trim for basic cleaning (can be removed if model handles it)
            // But skip trim for JSON columns (they need json_decode)
            if (str_contains($sourceColumn, 'json') || str_contains($target, '.*')) {
                return [];
            }
            return ['trim'];
        }

        $transforms = array_map('trim', explode(',', $transformsInput));

        return array_filter($transforms);
    }

    /**
     * Prompt for default value (optional, only if needed).
     *
     * @return mixed
     */
    private function promptDefault(string $sourceColumn, string $target): mixed
    {
        $this->command->line('');
        $this->command->line('  <fg=gray>Default value (optional): Use only if not set in model/migration. Leave empty for null.</fg=gray>');

        $defaultInput = $this->ask('  Default value (or empty for null):', '');

        if (empty(trim($defaultInput))) {
            return null;
        }

        // Try to parse as appropriate type
        if (strtolower($defaultInput) === 'null') {
            return null;
        }

        if (is_numeric($defaultInput)) {
            return str_contains($defaultInput, '.') ? (float) $defaultInput : (int) $defaultInput;
        }

        if (strtolower($defaultInput) === 'true') {
            return true;
        }

        if (strtolower($defaultInput) === 'false') {
            return false;
        }

        return $defaultInput;
    }

    /**
     * Prompt for validation rule (optional, only if needed).
     *
     * @return string|null
     */
    private function promptValidationRule(string $sourceColumn, string $target): ?string
    {
        $this->command->line('');
        $this->command->line('  <fg=gray>Validation rule (optional): Use only if model rules() method does not handle it. Leave empty if model validates.</fg=gray>');

        $ruleInput = $this->ask('  Validation rule (or empty if model handles it):', '');

        if (empty(trim($ruleInput))) {
            return null;
        }

        return $ruleInput;
    }

    /**
     * Interactive select prompt wrapper.
     *
     * @param  array<string, string>  $options  Array of [value => label]
     * @return string|null Selected value, or null if cancelled
     */
    private function select(string $label, array $options): ?string
    {
        if ($this->isNonInteractive()) {
            return null;
        }

        if (! function_exists('Laravel\Prompts\select')) {
            // Fallback if prompts not available
            $this->command->line("  {$label}");
            $this->command->line('  Available options:');
            foreach ($options as $value => $optionLabel) {
                $this->command->line("    - {$value} ({$optionLabel})");
            }

            return null;
        }

        return \Laravel\Prompts\select($label, $options);
    }

    /**
     * Interactive confirm prompt wrapper.
     */
    private function confirm(string $label, bool $default = false): bool
    {
        if ($this->isNonInteractive()) {
            return $default;
        }

        if (! function_exists('Laravel\Prompts\confirm')) {
            // Fallback if prompts not available
            return $this->command->confirm($label, $default);
        }

        return \Laravel\Prompts\confirm($label, default: $default);
    }

    /**
     * Interactive ask prompt wrapper.
     */
    private function ask(string $label, ?string $default = null): ?string
    {
        if ($this->isNonInteractive()) {
            return $default;
        }

        if (! function_exists('Laravel\Prompts\text')) {
            // Fallback if prompts not available
            return $this->command->ask($label, $default);
        }

        return \Laravel\Prompts\text($label, default: $default);
    }

    /**
     * Check if command is in non-interactive mode.
     */
    private function isNonInteractive(): bool
    {
        if (method_exists($this->command, 'isQuiet') && $this->command->isQuiet()) {
            return true;
        }

        return $this->command->option('no-interaction') === true;
    }
}

