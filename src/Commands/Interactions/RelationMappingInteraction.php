<?php

namespace InFlow\Commands\Interactions;

use InFlow\Commands\InFlowCommand;
use InFlow\Enums\EloquentRelationType;
use InFlow\Enums\InteractiveCommand;
use InFlow\Services\Core\InFlowConsoleServices;
use InFlow\ValueObjects\ColumnMetadata;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Handles relation mapping interactions during column mapping.
 */
class RelationMappingInteraction
{
    public function __construct(
        private readonly InFlowCommand $command,
        private readonly InFlowConsoleServices $services
    ) {}

    /**
     * Ask user to select a field from a relation.
     *
     * @return string|false The selected path (e.g., 'author.name') or false if cancelled
     */
    public function askForRelationField(string $relationName, string $modelClass): string|false
    {
        $relations = $this->services->modelSelectionService->getModelRelations($modelClass);

        if (! isset($relations[$relationName])) {
            return false;
        }

        $relatedModelClass = $relations[$relationName];
        $relatedFillable = $this->services->modelSelectionService->getAllModelAttributes($relatedModelClass);

        if (empty($relatedFillable)) {
            // TODO: Use model introspection to find a suitable field instead of hardcoded 'name'
            $this->command->warning('  No fillable attributes found in '.$relatedModelClass.". Using 'name' as default.");

            return "{$relationName}.name";
        }

        $fieldOptions = ['__back__' => '← Back'] + array_combine($relatedFillable, $relatedFillable);
        $selectedField = select(
            label: '  Choose field in \''.$relationName.'\' relation',
            options: $fieldOptions,
            scroll: min(count($fieldOptions), 15)
        );

        if ($selectedField === '__back__') {
            return false;
        }

        return "{$relationName}.{$selectedField}";
    }

    /**
     * Handle relation mapping with all steps (select relation, field, create_if_missing).
     *
     * @param  array<string, string>  $relations  Available relations (name => class)
     * @param  mixed  $columnMeta  Column metadata to check if it's an array JSON column
     */
    public function handleRelationMapping(string $sourceColumn, array $relations, string $modelClass = '', mixed $columnMeta = null): string
    {
        if (empty($relations)) {
            $this->command->warning('  No relations available. Please enter field name manually.');

            $customPath = text(
                label: '  Enter field name',
                required: true,
                validate: fn ($value) => empty(trim($value)) ? 'Field name cannot be empty' : null
            );

            return trim($customPath);
        }

        // Step 1: Select relation
        $relationOptions = ['__back__' => '← Back'] + $this->buildRelationOptions($relations);

        $selectedRelation = select(
            label: '  Select relation for column \''.$sourceColumn.'\'',
            options: $relationOptions,
            scroll: min(count($relationOptions), 15)
        );

        if ($selectedRelation === '__back__') {
            return InteractiveCommand::Back->value;
        }

        $relatedFillable = $this->services->modelSelectionService->getAllModelAttributes($relations[$selectedRelation]);

        // Check if this is a BelongsToMany relation (can have pivot fields)
        $relationType = $modelClass !== ''
            ? $this->services->relationTypeService->getRelationType($modelClass, $selectedRelation)
            : null;
        $isBelongsToMany = $relationType === EloquentRelationType::BelongsToMany;

        // Check if column is an array JSON column
        $isArrayColumn = $this->isArrayColumn($columnMeta);

        // Step 2: Select mapping type
        $mappingOptions = [
            '__back__' => '← Back',
            'field' => 'Map to a specific field (e.g., '.$selectedRelation.'.name)',
        ];

        // If column is an array JSON, suggest mapping entire array to relation
        if ($isArrayColumn && ($isBelongsToMany || $relationType === EloquentRelationType::HasMany)) {
            $mappingOptions['array'] = 'Map entire array to relation (e.g., '.$selectedRelation.'.*)';
        } else {
            $mappingOptions['relation'] = 'Map the whole relation (e.g., '.$selectedRelation.')';
        }

        // Add pivot option for BelongsToMany relations
        if ($isBelongsToMany) {
            $mappingOptions['pivot'] = 'Map to a pivot field (e.g., '.$selectedRelation.'.pivot.order)';
        }

        $mappingType = select(
            label: '  How do you want to map this relation?',
            options: $mappingOptions,
            default: $isArrayColumn ? 'array' : 'field'
        );

        if ($mappingType === '__back__') {
            return $this->handleRelationMapping($sourceColumn, $relations, $modelClass, $columnMeta);
        }

        if ($mappingType === 'relation') {
            return $selectedRelation;
        }

        if ($mappingType === 'array') {
            // Map entire array to relation using relation.* syntax
            $this->command->note("  <fg=gray>Array → Relation: Mapping entire array to relation '{$selectedRelation}.*'</>");
            return $selectedRelation.'.*';
        }

        if ($mappingType === 'pivot') {
            return $this->selectPivotField($selectedRelation);
        }

        if (empty($relatedFillable)) {
            // TODO: Use model introspection to find a suitable field instead of hardcoded 'name'
            $this->command->warning('  No fillable attributes found in '.$relations[$selectedRelation].". Using 'name' as default.");

            return "{$selectedRelation}.name";
        }

        // Step 3: Select field
        $fieldOptions = ['__back__' => '← Back'] + array_combine($relatedFillable, $relatedFillable);
        $selectedField = select(
            label: '  Choose field in \''.$selectedRelation.'\' relation',
            options: $fieldOptions,
            scroll: min(count($fieldOptions), 15)
        );

        if ($selectedField === '__back__') {
            return $this->handleRelationMapping($sourceColumn, $relations, $modelClass);
        }

        $targetPath = "{$selectedRelation}.{$selectedField}";

        // Step 4: For BelongsTo relations, ask about create_if_missing
        if ($modelClass !== '') {
            $targetPath = $this->askCreateIfMissing($targetPath, $modelClass, $relations);
        }

        return $targetPath;
    }

    /**
     * Ask if missing related records should be created for BelongsTo or BelongsToMany relations.
     *
     * @return string The target path, with '+' suffix if create_if_missing is enabled
     */
    public function askCreateIfMissing(string $targetPath, string $modelClass, array $relations = []): string
    {
        $pathParts = explode('.', $targetPath);
        if (count($pathParts) < 2) {
            return $targetPath;
        }

        $relationName = $pathParts[0];
        $relationType = $this->services->relationTypeService->getRelationType($modelClass, $relationName);

        // Only ask for BelongsTo and BelongsToMany (relations where we look up related models)
        $askableTypes = [EloquentRelationType::BelongsTo, EloquentRelationType::BelongsToMany];
        if (! in_array($relationType, $askableTypes, true)) {
            return $targetPath;
        }

        // Get related model name for display
        if (empty($relations)) {
            $relations = $this->services->modelSelectionService->getModelRelations($modelClass);
        }
        $relatedModelClass = $relations[$relationName] ?? null;
        $relatedModelName = $relatedModelClass ? class_basename($relatedModelClass) : $relationName;

        $createIfMissing = confirm(
            label: '  Create new '.$relatedModelName.' if not found?',
            default: $relationType === EloquentRelationType::BelongsToMany, // Default true for BelongsToMany
            yes: 'y',
            no: 'n',
            hint: 'If disabled, rows with missing related records will fail'
        );

        if ($createIfMissing) {
            return $targetPath.'+';
        }

        return $targetPath;
    }

    /**
     * Ask for multi-value delimiter for BelongsToMany relations.
     *
     * @return string|null The delimiter or null if no splitting
     */
    public function askMultiValueDelimiter(string $sourceColumn, string $sampleValue): ?string
    {
        // Detect likely delimiters in the sample
        $detectedDelimiters = [];
        if (str_contains($sampleValue, ',')) {
            $detectedDelimiters[','] = 'Comma (,) - e.g., "'.implode(',', array_slice(explode(',', $sampleValue), 0, 3)).'"';
        }
        if (str_contains($sampleValue, ';')) {
            $detectedDelimiters[';'] = 'Semicolon (;) - e.g., "'.implode(';', array_slice(explode(';', $sampleValue), 0, 3)).'"';
        }
        if (str_contains($sampleValue, '|')) {
            $detectedDelimiters['|'] = 'Pipe (|) - e.g., "'.implode('|', array_slice(explode('|', $sampleValue), 0, 3)).'"';
        }

        if (empty($detectedDelimiters)) {
            return null; // No multi-value detected
        }

        $options = [
            '__none__' => 'No split (treat as single value)',
        ] + $detectedDelimiters + [
            '__custom__' => 'Custom delimiter...',
        ];

        $selected = select(
            label: '  Multiple values detected in \''.$sourceColumn.'\'. Select delimiter:',
            options: $options,
            default: array_key_first($detectedDelimiters),
            hint: 'Sample: "'.$sampleValue.'"'
        );

        if ($selected === '__none__') {
            return null;
        }

        if ($selected === '__custom__') {
            $custom = text(
                label: '  Enter custom delimiter',
                required: true,
                validate: fn ($value) => strlen($value) === 0 ? 'Delimiter cannot be empty' : null
            );

            return $custom;
        }

        return $selected;
    }

    /**
     * Select a pivot field for BelongsToMany relations.
     */
    private function selectPivotField(string $relationName): string
    {
        // Common pivot fields
        $commonPivotFields = [
            'order' => 'order (sorting/position)',
            'quantity' => 'quantity (for quantity tracking)',
            'price' => 'price (for pricing)',
            'role' => 'role (for role assignment)',
            'created_at' => 'created_at (timestamp)',
            'custom' => 'Enter custom field name...',
        ];

        $pivotOptions = ['__back__' => '← Back'] + $commonPivotFields;

        $selectedField = select(
            label: '  Select pivot field for \''.$relationName.'\' relation',
            options: $pivotOptions,
            scroll: min(count($pivotOptions), 15)
        );

        if ($selectedField === '__back__') {
            return InteractiveCommand::Back->value;
        }

        if ($selectedField === 'custom') {
            $customField = text(
                label: '  Enter custom pivot field name',
                required: true,
                validate: fn ($value) => empty(trim($value)) ? 'Field name cannot be empty' : null
            );

            return "{$relationName}.pivot.".trim($customField);
        }

        return "{$relationName}.pivot.{$selectedField}";
    }

    /**
     * Build relation options for select menu.
     *
     * @param  array<string, string>  $relations
     * @return array<string, string>
     */
    private function buildRelationOptions(array $relations): array
    {
        $relationOptions = [];

        foreach ($relations as $relationName => $relatedModelClass) {
            $shortModelName = class_basename($relatedModelClass);
            $relationOptions[$relationName] = $relationName.' → '.$shortModelName;
        }

        return $relationOptions;
    }

    /**
     * Check if column metadata indicates an array/JSON column.
     */
    private function isArrayColumn(mixed $columnMeta): bool
    {
        if ($columnMeta === null || ! ($columnMeta instanceof ColumnMetadata) || empty($columnMeta->examples)) {
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
}
