<?php

namespace InFlow\Commands\Interactions;

use InFlow\Commands\InFlowCommand;
use InFlow\Constants\DisplayConstants;
use InFlow\Enums\FieldHandlerAction;
use InFlow\Enums\InteractiveCommand;
use InFlow\Services\Core\InFlowConsoleServices;
use InFlow\ValueObjects\MappingDefinition;
use InFlow\ValueObjects\SourceSchema;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class ValidationInteraction
{
    private ?MappingDefinition $currentMapping = null;

    public function __construct(
        private readonly InFlowCommand $command,
        private readonly InFlowConsoleServices $services,
        private readonly MappingInteraction $mappingInteraction
    ) {}

    public function validateMappingBeforeExecution(MappingDefinition $mapping, SourceSchema $sourceSchema): bool|MappingDefinition
    {
        // Store mapping for use in transform field selection
        $this->currentMapping = $mapping;

        if ($this->command->isQuiet() || $this->command->option('no-interaction')) {
            return true;
        }

        $modelClass = $this->mappingInteraction->getModelClass();
        if ($modelClass === null || ! class_exists($modelClass)) {
            return true;
        }

        $validationData = $this->prepareValidationData($mapping, $modelClass);
        if ($validationData === null) {
            return true;
        }

        [
            'missingFields' => $missingFields,
            'missingRelationRequired' => $missingRelationRequired,
            'missingRelationConditional' => $missingRelationConditional,
            'modelAnalysis' => $modelAnalysis,
            'fillable' => $fillable,
            'guarded' => $guarded,
            'validationRules' => $validationRules,
        ] = $validationData;

        $fieldInfo = $modelAnalysis['field_info'] ?? [];
        $suggestions = $this->services->mappingValidationService->suggestAutoMapping($missingFields, $sourceSchema);

        // Single unified display: warning + suggestions + action choice
        $this->displayValidationWarningWithSuggestions(
            $missingFields,
            $missingRelationRequired,
            $missingRelationConditional,
            $modelAnalysis,
            $fillable,
            $guarded,
            $validationRules,
            $suggestions
        );

        return $this->promptValidationAction($mapping, $missingFields, $suggestions, $sourceSchema, $modelClass, $fieldInfo);
    }

    private function prepareValidationData(MappingDefinition $mapping, string $modelClass): ?array
    {
        $model = new $modelClass;
        $fillable = $model->getFillable();
        $guarded = $model->getGuarded();

        $validationRules = $this->services->mappingValidationService->getModelValidationRules($modelClass);

        $mappedData = $this->services->mappingValidationService->extractMappedColumns($mapping);
        $mappedColumns = $mappedData['main'];
        $relationColumns = $mappedData['relations'];
        $relationMeta = $mappedData['relation_meta'] ?? [];

        $modelAnalysis = $this->services->mappingValidationService->analyzeModelConstraints($modelClass, $fillable, $guarded, $validationRules);

        $missingFields = $this->services->mappingValidationService->identifyMissingFields(
            $modelAnalysis['required_fields'],
            $mappedColumns,
            $validationRules,
            array_keys($relationColumns) // Exclude FK for mapped relations (auto-resolved)
        );

        $missingRelationFields = $this->services->mappingValidationService->analyzeRelationConstraints($modelClass, $relationColumns, $relationMeta);

        $missingRelationRequired = [];
        $missingRelationConditional = [];

        foreach (($missingRelationFields['required'] ?? []) as $relationName => $fields) {
            foreach ($fields as $field) {
                $path = "{$relationName}.{$field}";
                $missingRelationRequired[] = $path;
                $missingFields[] = $path;
            }
        }

        foreach (($missingRelationFields['conditional'] ?? []) as $relationName => $fields) {
            foreach ($fields as $field) {
                $path = "{$relationName}.{$field}";
                $missingRelationConditional[] = $path;
                $missingFields[] = $path;
            }
        }

        if (empty($missingFields)) {
            return null;
        }

        return [
            'missingFields' => $missingFields,
            'missingRelationRequired' => array_values(array_unique($missingRelationRequired)),
            'missingRelationConditional' => array_values(array_unique($missingRelationConditional)),
            'modelAnalysis' => $modelAnalysis,
            'fillable' => $fillable,
            'guarded' => $guarded,
            'validationRules' => $validationRules,
        ];
    }

    private function displayValidationWarningWithSuggestions(
        array $missingFields,
        array $missingRelationRequired,
        array $missingRelationConditional,
        array $modelAnalysis,
        array $fillable,
        array $guarded,
        array $validationRules,
        array $suggestions
    ): void {
        $this->command->newLine();
        $this->command->line('<fg=yellow>Mapping Validation Warning</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);
        $this->command->line('  <fg=red>The following required fields are not mapped:</>');

        $mainOnly = array_values(array_diff($missingFields, array_merge($missingRelationRequired, $missingRelationConditional)));

        if (! empty($mainOnly)) {
            $this->command->line('  <fg=cyan>Main model:</>');
            foreach ($mainOnly as $field) {
                $this->displayFieldWithSuggestion($field, $modelAnalysis, $validationRules, $fillable, $guarded, $suggestions);
            }
            $this->command->newLine();
        }

        if (! empty($missingRelationRequired)) {
            $this->command->line('  <fg=cyan>Relations (required when relation is written):</>');
            foreach ($missingRelationRequired as $field) {
                $this->displayFieldWithSuggestion($field, $modelAnalysis, $validationRules, $fillable, $guarded, $suggestions);
            }
            $this->command->newLine();
        }

        if (! empty($missingRelationConditional)) {
            $this->command->line('  <fg=cyan>Relations (required only if relation is present):</>');
            foreach ($missingRelationConditional as $field) {
                $this->displayFieldWithSuggestion($field, $modelAnalysis, $validationRules, $fillable, $guarded, $suggestions);
            }
            $this->command->newLine();
        }

        if ($this->command->getOutput()->isVerbose()) {
            foreach ($this->services->mappingValidationService->formatModelAnalysisSummary($fillable, $guarded, $validationRules) as $line) {
                $this->command->line($line);
            }
            $this->command->newLine();
        }
    }

    private function displayFieldWithSuggestion(string $field, array $modelAnalysis, array $validationRules, array $fillable, array $guarded, array $suggestions): void
    {
        $info = $this->services->mappingValidationService->getFieldInfo(
            $field,
            $modelAnalysis['required_fields'],
            $validationRules,
            $fillable,
            $guarded
        );

        $line = $this->services->mappingValidationService->formatMissingFieldLine($field, $info);

        // Append suggestion inline if available
        if (isset($suggestions[$field])) {
            $source = $suggestions[$field]['source'];
            $confidence = $suggestions[$field]['confidence'] ?? 0;
            $confidenceColor = $this->getConfidenceColor($confidence);
            $line .= " <fg=cyan>← {$source}</> <fg={$confidenceColor}>(".number_format($confidence * 100, 0).'%)</>';
        }

        $this->command->line($line);
    }

    private function promptValidationAction(
        MappingDefinition $mapping,
        array $missingFields,
        array $suggestions,
        SourceSchema $sourceSchema,
        string $modelClass,
        array $fieldInfo
    ): bool|MappingDefinition {
        $hasSuggestions = ! empty($suggestions);

        $options = [];
        if ($hasSuggestions) {
            $options['apply'] = 'Apply all suggested mappings ('.count($suggestions).' fields)';
        }
        $options['manual'] = 'Map fields manually (one by one)';
        $options['skip'] = 'Skip and continue without changes';
        $options['cancel'] = 'Cancel import';

        $default = $hasSuggestions ? 'apply' : 'manual';

        $action = select(
            label: '  How do you want to proceed?',
            options: $options,
            default: $default
        );

        return match ($action) {
            'apply' => $this->applyAllSuggestions($mapping, $suggestions, $sourceSchema, $modelClass),
            'manual' => $this->handleFieldHandlers($mapping, $missingFields, $suggestions, $sourceSchema, $modelClass, $fieldInfo),
            'skip' => true,
            'cancel' => false,
            default => false,
        };
    }

    private function applyAllSuggestions(MappingDefinition $mapping, array $suggestions, SourceSchema $sourceSchema, string $modelClass): bool|MappingDefinition
    {
        $updatedMapping = $this->services->mappingValidationService->applyAutoMapping($mapping, $suggestions, $sourceSchema, $modelClass);

        if ($updatedMapping === null) {
            $this->command->warning('  Failed to apply suggestions.');

            return true;
        }

        $updatedMapping = $this->services->mappingValidationService->preserveMappingOptions($updatedMapping, $mapping);

        return $this->saveUpdatedMapping($updatedMapping, $modelClass);
    }

    private function handleFieldHandlers(
        MappingDefinition $mapping,
        array $missingFields,
        array $suggestions,
        SourceSchema $sourceSchema,
        string $modelClass,
        array $fieldInfo
    ): bool|MappingDefinition {
        $fieldHandlers = [];

        foreach ($missingFields as $field) {
            $fieldData = $fieldInfo[$field] ?? null;
            $handler = $this->askHowToHandleMissingField($field, $sourceSchema, $suggestions[$field] ?? null, $fieldData);

            if ($handler === null) {
                return false; // User cancelled
            }

            $fieldHandlers[$field] = $handler;
        }

        if (empty($fieldHandlers)) {
            return true; // No handlers needed, continue
        }

        $updatedMapping = $this->services->mappingValidationService->applyFieldHandlers($mapping, $fieldHandlers, $sourceSchema, $modelClass);

        if ($updatedMapping === null) {
            return true; // Could not apply handlers, continue anyway
        }

        $updatedMapping = $this->services->mappingValidationService->preserveMappingOptions($updatedMapping, $mapping);

        return $this->saveUpdatedMapping($updatedMapping, $modelClass);
    }

    private function saveUpdatedMapping(MappingDefinition $updatedMapping, string $modelClass): bool|MappingDefinition
    {
        $saveMappingPath = $this->services->configResolver->getMappingPathFromModel($modelClass);

        try {
            $this->services->mappingSerializer->saveToFile($updatedMapping, $saveMappingPath);
            $this->command->success('Mapping updated and saved.');
            $this->command->newLine();

            return $updatedMapping;
        } catch (\Exception $e) {
            \inflow_report($e, 'error', ['operation' => 'saveUpdatedMapping', 'path' => $saveMappingPath ?? null]);
            $this->command->error('  Failed to save updated mapping: '.$e->getMessage());

            return false;
        }
    }

    private function askHowToHandleMissingField(string $field, SourceSchema $sourceSchema, ?array $suggestion = null, ?array $fieldData = null): ?array
    {
        $this->command->line('  <fg=cyan>How to handle missing field:</> <fg=yellow>'.$field.'</>');

        $options = FieldHandlerAction::options();
        if ($suggestion !== null) {
            $options[FieldHandlerAction::Map->value] = "Map from '{$suggestion['source']}' (suggested)";
        }
        $options = array_merge($options, [FieldHandlerAction::Cancel->value => FieldHandlerAction::Cancel->label()]);

        $action = select(
            label: '  Choose action for '.$field,
            options: $options,
            default: $suggestion !== null ? FieldHandlerAction::Map->value : FieldHandlerAction::Default->value
        );

        if ($action === FieldHandlerAction::Cancel->value) {
            return null;
        }

        return $this->processFieldHandlerAction($action, $field, $sourceSchema, $suggestion, $fieldData);
    }

    private function processFieldHandlerAction(string $action, string $field, SourceSchema $sourceSchema, ?array $suggestion, ?array $fieldData = null): ?array
    {
        $handler = ['action' => $action];

        return match ($action) {
            FieldHandlerAction::Default->value => $this->handleDefaultValueAction($field, $handler, $sourceSchema, $suggestion, $fieldData),
            FieldHandlerAction::Map->value => $this->handleMapAction($field, $handler, $sourceSchema, $suggestion, $fieldData),
            FieldHandlerAction::Transform->value => $this->handleTransformAction($field, $handler, $sourceSchema, $suggestion, $fieldData),
            FieldHandlerAction::Skip->value => $this->handleSkipAction($field, $handler, $sourceSchema, $suggestion, $fieldData),
            default => $handler,
        };
    }

    private function handleDefaultValueAction(string $field, array $handler, SourceSchema $sourceSchema, ?array $suggestion, ?array $fieldData = null): ?array
    {
        $suggestedDefault = $this->suggestDefaultValue($fieldData);
        $hint = $suggestedDefault !== null ? " (suggested: {$suggestedDefault})" : '';

        $defaultValue = $this->command->ask("  Enter default value for {$field}{$hint} (or 'back' to go back)", $suggestedDefault ?? '');

        if ($defaultValue !== null && InteractiveCommand::isBack($defaultValue)) {
            return $this->askHowToHandleMissingField($field, $sourceSchema, $suggestion, $fieldData);
        }

        $handler['value'] = $this->convertDefaultValue((string) ($defaultValue ?? ''), $fieldData);

        return $handler;
    }

    private function suggestDefaultValue(?array $fieldData): ?string
    {
        if ($fieldData === null) {
            return null;
        }

        $type = strtolower($fieldData['type'] ?? '');

        if ($fieldData['default'] !== null) {
            return (string) $fieldData['default'];
        }

        if (str_contains($type, 'int')) {
            return '0';
        }

        if (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            return '0.0';
        }

        if (str_contains($type, 'bool') || str_contains($type, 'tinyint(1)')) {
            return '0';
        }

        if (str_contains($type, 'date') || str_contains($type, 'time')) {
            return null;
        }

        return '';
    }

    private function convertDefaultValue(string $value, ?array $fieldData): mixed
    {
        if ($fieldData === null || $value === '') {
            return $value;
        }

        $type = strtolower($fieldData['type'] ?? '');

        if (str_contains($type, 'int')) {
            return (int) $value;
        }

        if (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            return (float) $value;
        }

        if (str_contains($type, 'bool') || str_contains($type, 'tinyint(1)')) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
        }

        return $value;
    }

    private function handleMapAction(string $field, array $handler, SourceSchema $sourceSchema, ?array $suggestion, ?array $fieldData = null): ?array
    {
        if ($suggestion !== null) {
            $handler['source'] = $suggestion['source'];

            return $handler;
        }

        $availableColumns = $sourceSchema->getColumnNames();
        if (empty($availableColumns)) {
            $this->command->line('  <fg=yellow>No source columns available. Please use default value.</>');

            return $this->askHowToHandleMissingField($field, $sourceSchema, $suggestion, $fieldData);
        }

        // Analyze field context and find relevant columns
        $fieldContext = $this->analyzeFieldContext($field);
        $relevantColumns = $this->findRelevantColumns($fieldContext, $availableColumns);

        // Show contextual warning if no relevant columns found
        if (empty($relevantColumns) && $fieldContext['isRelation']) {
            $this->displayNoRelevantColumnsWarning($fieldContext, $availableColumns);

            // Offer to go back or choose alternative action
            $continueAnyway = confirm(
                label: 'Continue anyway and select from available columns?',
                default: false,
                yes: 'y',
                no: 'n',
                hint: 'Select "n" to go back and choose a different action'
            );

            if (! $continueAnyway) {
                return $this->askHowToHandleMissingField($field, $sourceSchema, $suggestion, $fieldData);
            }
        }

        // Build options with relevance grouping
        $columnOptions = $this->buildColumnOptionsWithRelevance(
            $relevantColumns,
            $availableColumns,
            $fieldContext
        );

        $selectedColumn = select(
            label: '  Select source column for '.$field,
            options: $columnOptions
        );

        // Handle back and non-selectable options
        if ($selectedColumn === DisplayConstants::BACK_KEYWORD || $selectedColumn === '__separator__') {
            return $this->askHowToHandleMissingField($field, $sourceSchema, $suggestion, $fieldData);
        }

        $handler['source'] = $selectedColumn;

        return $handler;
    }

    /**
     * Analyze the field to understand its context (relation, field name, etc.).
     */
    private function analyzeFieldContext(string $field): array
    {
        $parts = explode('.', $field);
        $isRelation = count($parts) > 1;
        $relationName = $isRelation ? $parts[0] : null;
        $fieldName = $isRelation ? end($parts) : $field;

        return [
            'fullPath' => $field,
            'isRelation' => $isRelation,
            'relationName' => $relationName,
            'fieldName' => $fieldName,
            'normalizedField' => $this->normalizeForMatch($fieldName),
            'normalizedRelation' => $relationName ? $this->normalizeForMatch($relationName) : null,
        ];
    }

    /**
     * Find columns that are relevant for the given field.
     *
     * For relation fields like `author.name`, we look for columns that:
     * 1. Match the field name exactly (e.g., "name")
     * 2. Contain the field name (e.g., "author_name" contains "name")
     * 3. Match both relation prefix AND field name (e.g., "author_name" starts with "author" and contains "name")
     *
     * We explicitly EXCLUDE columns that only match the relation prefix (e.g., "author_email" for "author.name")
     * because they don't contain relevant data for the target field.
     */
    private function findRelevantColumns(array $fieldContext, array $availableColumns): array
    {
        $relevant = [];

        foreach ($availableColumns as $column) {
            $normalized = $this->normalizeForMatch($column);

            // Exact match on field name (e.g., "name" == "name")
            if ($normalized === $fieldContext['normalizedField']) {
                $relevant[$column] = 'exact';

                continue;
            }

            // Column contains field name (e.g., "author_name" contains "name")
            if (str_contains($normalized, $fieldContext['normalizedField'])) {
                $relevant[$column] = 'contains';

                continue;
            }

            // For relation fields: only consider relevant if BOTH relation prefix AND field are present
            // e.g., for "author.name", only "author_name" is relevant, NOT "author_email"
            if ($fieldContext['isRelation'] && $fieldContext['normalizedRelation']) {
                $hasRelationPrefix = str_starts_with($normalized, $fieldContext['normalizedRelation']);
                $hasFieldMatch = str_contains($normalized, $fieldContext['normalizedField']);

                if ($hasRelationPrefix && $hasFieldMatch) {
                    $relevant[$column] = 'relation_field';
                }
            }
        }

        return $relevant;
    }

    /**
     * Display a clear warning when no relevant columns are found for a relation field.
     */
    private function displayNoRelevantColumnsWarning(array $fieldContext, array $availableColumns): void
    {
        $this->command->newLine();
        $this->command->line('  <fg=yellow>⚠ No matching source column found</>');
        $this->command->newLine();

        $this->command->line(sprintf(
            '  Field <fg=cyan>%s</> belongs to the <fg=cyan>%s</> relation,',
            $fieldContext['fieldName'],
            ucfirst($fieldContext['relationName'])
        ));
        $this->command->line('  but no column in the source file contains this data.');
        $this->command->newLine();

        $this->command->line('  <fg=gray>Available columns in source file:</>');
        $this->command->line('  <fg=gray>'.implode(', ', $availableColumns).'</>');
        $this->command->newLine();

        $this->command->line('  <fg=white>Suggested alternatives:</>');
        $this->command->line('  • <fg=green>Set a default value</> - Use a fixed value for all records');
        $this->command->line('  • <fg=green>Generate from another field</> - e.g., generate slug from name');
        $this->command->line('  • <fg=green>Skip this field</> - Leave it null (if nullable)');
        $this->command->line('  • <fg=yellow>Go back to mapping</> - Disable "create if not found" for this relation');
        $this->command->newLine();
    }

    /**
     * Build column options grouped by relevance.
     */
    private function buildColumnOptionsWithRelevance(
        array $relevantColumns,
        array $availableColumns,
        array $fieldContext
    ): array {
        $options = [];

        // Add relevant columns first with indicators
        if (! empty($relevantColumns)) {
            foreach ($relevantColumns as $column => $matchType) {
                $indicator = match ($matchType) {
                    'exact' => '★',
                    'contains' => '✓',
                    'relation_field' => '✓',
                    default => ' ',
                };
                $options[$column] = "{$indicator} {$column}";
            }

            // Separator
            $options['__separator__'] = '─── Other columns ───';
        }

        // Add remaining columns
        foreach ($availableColumns as $column) {
            if (! isset($relevantColumns[$column])) {
                $options[$column] = "  {$column}";
            }
        }

        $options[DisplayConstants::BACK_KEYWORD] = '← Go back';

        return $options;
    }

    /**
     * Normalize a string for matching (lowercase, remove underscores/hyphens).
     */
    private function normalizeForMatch(string $value): string
    {
        return strtolower(str_replace(['_', '-'], '', $value));
    }

    private function handleTransformAction(string $field, array $handler, SourceSchema $sourceSchema, ?array $suggestion, ?array $fieldData = null): ?array
    {
        // Detect if this is a slug field
        $isSlugField = str_ends_with($field, '.slug') || $field === 'slug';

        // Suggest transforms based on field type
        $transforms = [
            'slugify' => 'Slugify (convert to URL-friendly string)',
            'lowercase' => 'Lowercase',
            'uppercase' => 'Uppercase',
            'trim' => 'Trim whitespace',
            '__back__' => '← Back',
        ];

        $selectedTransform = select(
            label: '  Select transform to generate \''.$field.'\'',
            options: $transforms,
            default: $isSlugField ? 'slugify' : 'trim'
        );

        if ($selectedTransform === '__back__') {
            return $this->askHowToHandleMissingField($field, $sourceSchema, $suggestion, $fieldData);
        }

        // Ask which mapped field to use as source
        $mappedFields = $this->getMappedFieldsForRelation($field, $sourceSchema);
        if (empty($mappedFields)) {
            $this->command->warning('  No mapped fields available in this relation to generate from.');

            return $this->askHowToHandleMissingField($field, $sourceSchema, $suggestion, $fieldData);
        }

        $sourceField = select(
            label: '  Generate from which mapped field?',
            options: array_merge(['__back__' => '← Back'], $mappedFields),
            default: array_key_first($mappedFields)
        );

        if ($sourceField === '__back__') {
            return $this->askHowToHandleMissingField($field, $sourceSchema, $suggestion, $fieldData);
        }

        $handler['transform'] = $selectedTransform;
        $handler['source_field'] = $sourceField;

        return $handler;
    }

    /**
     * Get mapped fields for a relation to use as transform source.
     *
     * @return array<string, string> field => label
     */
    private function getMappedFieldsForRelation(string $targetField, SourceSchema $sourceSchema): array
    {
        // Extract relation name and target field name (e.g., "tags.slug" -> "tags", "slug")
        $pathParts = explode('.', $targetField);
        if (count($pathParts) < 2) {
            return [];
        }

        $relationName = $pathParts[0];
        $targetFieldName = $pathParts[1];

        // Get the current mapping from context (stored during validation)
        $currentMapping = $this->currentMapping ?? null;
        if ($currentMapping === null) {
            return [];
        }

        // Find all fields mapped to this relation, excluding the target field
        $options = [];
        foreach ($currentMapping->mappings as $modelMapping) {
            foreach ($modelMapping->columns as $columnMapping) {
                $mappedPath = rtrim($columnMapping->targetPath, '+');
                $mappedParts = explode('.', $mappedPath);

                if (count($mappedParts) >= 2 && $mappedParts[0] === $relationName) {
                    $mappedField = $mappedParts[1];

                    // Exclude the target field itself and pivot fields
                    if ($mappedField !== $targetFieldName && $mappedField !== 'pivot') {
                        $options[$mappedPath] = $mappedPath;
                    }
                }
            }
        }

        return $options;
    }

    private function handleSkipAction(string $field, array $handler, SourceSchema $sourceSchema, ?array $suggestion, ?array $fieldData = null): ?array
    {
        $confirmed = $this->command->confirm("  Are you sure you want to skip '{$field}'? This will cause import errors.");

        if (! $confirmed) {
            return $this->askHowToHandleMissingField($field, $sourceSchema, $suggestion, $fieldData);
        }

        return $handler;
    }

    /**
     * Get color based on confidence level.
     */
    private function getConfidenceColor(float $confidence): string
    {
        if ($confidence >= DisplayConstants::CONFIDENCE_THRESHOLD_HIGH) {
            return 'green';
        }

        if ($confidence >= DisplayConstants::CONFIDENCE_THRESHOLD_MEDIUM) {
            return 'yellow';
        }

        return 'red';
    }
}
