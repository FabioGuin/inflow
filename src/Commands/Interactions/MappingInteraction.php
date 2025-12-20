<?php

namespace InFlow\Commands\Interactions;

use InFlow\Commands\InFlowCommand;
use InFlow\Constants\DisplayConstants;
use InFlow\Enums\Data\CustomMappingAction;
use InFlow\Enums\Data\EloquentRelationType;
use InFlow\Enums\UI\InteractiveCommand;
use InFlow\Enums\Data\MappingHistoryAction;
use InFlow\Services\File\ModelSelectionService;
use InFlow\Services\Loading\RelationTypeService;
use InFlow\Services\Mapping\MappingHistoryService;
use InFlow\ValueObjects\Mapping\MappingDefinition;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Handles column mapping interactions during the mapping setup process.
 *
 * Delegates specialized concerns to:
 * - DuplicateHandlingInteraction: duplicate handling configuration
 * - RelationMappingInteraction: relation field selection and create_if_missing
 */
class MappingInteraction
{
    private ?DuplicateHandlingInteraction $duplicateHandling = null;

    private ?RelationMappingInteraction $relationMapping = null;

    public function __construct(
        private readonly InFlowCommand $command,
        private readonly ModelSelectionService $modelSelectionService,
        private readonly MappingHistoryService $mappingHistoryService,
        private readonly RelationTypeService $relationTypeService
    ) {}

    private function duplicateHandling(): DuplicateHandlingInteraction
    {
        return $this->duplicateHandling ??= new DuplicateHandlingInteraction($this->command, $this->modelSelectionService);
    }

    private function relationMapping(): RelationMappingInteraction
    {
        return $this->relationMapping ??= new RelationMappingInteraction($this->command, $this->modelSelectionService, $this->relationTypeService);
    }

    public function getModelClass(): ?string
    {
        $modelClass = $this->command->argument('to');
        if ($modelClass !== null) {
            return $this->modelSelectionService->normalizeModelClass($modelClass);
        }

        if ($this->command->option('no-interaction')) {
            $this->command->error('Model class is required when running in non-interactive mode.');

            return null;
        }

        return $this->promptForModelClass();
    }

    public function detectUniqueKeys(string $modelClass, string $table): array
    {
        return $this->duplicateHandling()->detectUniqueKeys($modelClass, $table);
    }

    public function configureDuplicateHandling(MappingDefinition $mapping, string $modelClass): MappingDefinition
    {
        if ($this->isDuplicateHandlingConfigured($mapping)) {
            return $mapping;
        }

        return $this->duplicateHandling()->configure($mapping, $modelClass);
    }

    public function handleColumnMapping(
        string $sourceColumn,
        string $suggestedPath,
        float $confidence,
        array $alternatives,
        bool $isRelation,
        string $modelClass,
        array &$mappingHistory,
        int &$currentIndex,
        bool $isArrayRelation = false,
        mixed $columnMeta = null
    ): string|bool|array {
        $this->displayColumnMappingInfo($sourceColumn, $suggestedPath, $confidence, $alternatives, $isRelation);

        if ($this->command->isQuiet() || $this->command->option('no-interaction')) {
            $finalPath = $suggestedPath;

            // For array relations, append .* to map entire array
            if ($isArrayRelation) {
                $finalPath = $suggestedPath.'.*';
            }

            $this->mappingHistoryService->addEntry(
                $mappingHistory,
                $currentIndex,
                $sourceColumn,
                $finalPath,
                MappingHistoryAction::Accepted
            );

            return $finalPath;
        }

        $hasPrevious = $this->mappingHistoryService->hasPrevious($currentIndex);

        if ($hasPrevious) {
            $this->command->line('  <fg=gray>Press Ctrl+C to cancel, or continue...</>');
        }

        $confirmed = confirm(label: '  Use this mapping?', default: false, yes: 'y', no: 'n');

        if (! $confirmed) {
            return $this->handleCustomMapping($sourceColumn, $modelClass, $hasPrevious, $mappingHistory, $currentIndex, $columnMeta);
        }

        $finalPath = $suggestedPath;
        $delimiter = null;

        // For array relations (HasMany/BelongsToMany with array source), map directly to relation
        // No need to select a specific field - the entire array will be synced
        if ($isArrayRelation) {
            // Use special syntax 'relation.*' to indicate full array mapping
            $finalPath = $suggestedPath.'.*';
            $this->command->note("  <fg=gray>Array → HasMany: Mapping entire array to relation '{$suggestedPath}'</>");
        } elseif ($isRelation && ! str_contains($suggestedPath, '.')) {
            $finalPath = $this->relationMapping()->askForRelationField($suggestedPath, $modelClass);
            if ($finalPath === false) {
                return $this->handleCustomMapping($sourceColumn, $modelClass, $hasPrevious, $mappingHistory, $currentIndex, $columnMeta);
            }
        }

        // For BelongsTo/BelongsToMany relations, ask about create_if_missing
        if ($isRelation && str_contains($finalPath, '.') && ! $isArrayRelation) {
            $finalPath = $this->relationMapping()->askCreateIfMissing($finalPath, $modelClass);
        }

        // For BelongsToMany relations with string values, ask about delimiter
        if ($isRelation && ! $isArrayRelation && $columnMeta !== null) {
            $delimiter = $this->askDelimiterForBelongsToMany($finalPath, $modelClass, $sourceColumn, $columnMeta);
        }

        $this->services->mappingHistoryService->addEntry(
            $mappingHistory,
            $currentIndex,
            $sourceColumn,
            $finalPath,
            MappingHistoryAction::Accepted
        );

        // Return array if delimiter is set, otherwise just the path
        if ($delimiter !== null) {
            return ['path' => $finalPath, 'delimiter' => $delimiter];
        }

        return $finalPath;
    }

    private function promptForModelClass(): ?string
    {
        $modelClass = $this->command->textWithValidation(
            label: 'Enter target model class (FQCN, e.g., App\\Models\\User)',
            required: true,
            validate: fn ($value) => $this->modelSelectionService->validateModelClass($value)
        );

        if ($modelClass === null) {
            return null;
        }

        return $this->services->modelSelectionService->normalizeModelClass($modelClass);
    }

    private function handleCustomMapping(
        string $sourceColumn,
        string $modelClass,
        bool $hasPrevious,
        array &$mappingHistory,
        int &$currentIndex,
        mixed $columnMeta = null
    ): string|bool|array {
        // Back navigation is handled via menu options

        $targetPath = $this->askForCustomMapping($sourceColumn, $modelClass, $hasPrevious, $columnMeta);

        // Handle back navigation from custom mapping
        if ($targetPath === InteractiveCommand::Back->value) {
            if ($hasPrevious) {
                return $this->handleHistoryNavigation($mappingHistory, $currentIndex, $modelClass);
            }

            return $this->handleCustomMapping($sourceColumn, $modelClass, false, $mappingHistory, $currentIndex, $columnMeta);
        }

        // Handle skip
        if ($targetPath === false) {
            $this->mappingHistoryService->addEntry(
                $mappingHistory,
                $currentIndex,
                $sourceColumn,
                '',
                MappingHistoryAction::Skipped
            );

            return false;
        }

        // For BelongsToMany relations with string values, ask about delimiter
        $delimiter = null;
        if (str_contains($targetPath, '.') && $columnMeta !== null) {
            $delimiter = $this->askDelimiterForBelongsToMany($targetPath, $modelClass, $sourceColumn, $columnMeta);
        }

        $this->services->mappingHistoryService->addEntry(
            $mappingHistory,
            $currentIndex,
            $sourceColumn,
            $targetPath,
            MappingHistoryAction::Custom
        );

        // Return array if delimiter is set, otherwise just the path
        if ($delimiter !== null) {
            return ['path' => $targetPath, 'delimiter' => $delimiter];
        }

        return $targetPath;
    }

    /**
     * Ask for delimiter if this is a BelongsToMany relation with potential multi-values.
     */
    private function askDelimiterForBelongsToMany(
        string $targetPath,
        string $modelClass,
        string $sourceColumn,
        mixed $columnMeta
    ): ?string {
        $pathParts = explode('.', $targetPath);
        if (count($pathParts) < 2) {
            return null;
        }

        $relationName = $pathParts[0];
        $relationType = $this->relationTypeService->getRelationType($modelClass, $relationName);

        // Only ask for BelongsToMany relations
        if ($relationType !== EloquentRelationType::BelongsToMany) {
            return null;
        }

        // Get sample value from column metadata
        $sampleValue = $this->getSampleValue($columnMeta);
        if ($sampleValue === null) {
            return null;
        }

        // Only ask if sample contains potential delimiters
        if (! str_contains($sampleValue, ',') && ! str_contains($sampleValue, ';') && ! str_contains($sampleValue, '|')) {
            return null;
        }

        return $this->relationMapping()->askMultiValueDelimiter($sourceColumn, $sampleValue);
    }

    /**
     * Get a sample value from column metadata.
     */
    private function getSampleValue(mixed $columnMeta): ?string
    {
        if ($columnMeta === null) {
            return null;
        }

        // Get first non-empty example
        $examples = $columnMeta->examples ?? [];
        foreach ($examples as $example) {
            if (is_string($example) && $example !== '') {
                return $example;
            }
        }

        return null;
    }

    private function handleHistoryNavigation(
        array &$mappingHistory,
        int &$currentIndex,
        string $modelClass
    ): string|bool {
        $action = $this->displayHistoryMenu($mappingHistory);

        if ($action === 'continue') {
            $lastEntry = end($mappingHistory);

            return $lastEntry['target'] ?? false;
        }

        if (is_int($action)) {
            // Jump to specific index
            $currentIndex = $action;
            $entry = $mappingHistory[$action];
            $sourceColumn = $entry['source'];
            $hasPrevious = $this->mappingHistoryService->hasPrevious($currentIndex);

            return $this->handleCustomMapping($sourceColumn, $modelClass, $hasPrevious, $mappingHistory, $currentIndex);
        }

        return false;
    }

    private function displayHistoryMenu(array $mappingHistory): int|string
    {
        $options = [];

        foreach ($mappingHistory as $index => $entry) {
            $status = match ($entry['action']) {
                MappingHistoryAction::Accepted->value => '✓',
                MappingHistoryAction::Skipped->value => '⊘',
                MappingHistoryAction::Custom->value => '✎',
                default => '?',
            };
            $target = $entry['target'] ?: '<skipped>';
            $options[$index] = "{$status} {$entry['source']} → {$target}";
        }

        $options['continue'] = '→ Continue';

        return select(
            label: 'Mapping history - select to edit or continue',
            options: $options,
            default: 'continue'
        );
    }

    private function displayColumnMappingInfo(
        string $sourceColumn,
        string $suggestedPath,
        float $confidence,
        array $alternatives,
        bool $isRelation
    ): void {
        $this->command->newLine();

        $relationPrefix = $isRelation ? '<fg=magenta>[Relation]</> ' : '';
        $confidenceColor = $confidence >= DisplayConstants::CONFIDENCE_THRESHOLD_HIGH ? 'green' :
                          ($confidence >= DisplayConstants::CONFIDENCE_THRESHOLD_MEDIUM ? 'yellow' : 'red');

        $this->command->line('  <fg=cyan>Column:</> <fg=yellow>'.$sourceColumn.'</>');
        $this->command->line('  <fg=cyan>Suggested:</> '.$relationPrefix.'<fg=white>'.$suggestedPath.'</> <fg='.$confidenceColor.'>(confidence: '.number_format($confidence * 100, 0).'%)</>');

        // Display alternatives
        $fields = [];
        $relations = [];

        foreach ($alternatives as $alt) {
            // Handle both string format and array format
            if (is_string($alt)) {
                $path = $alt;
                $isRelation = str_contains($alt, '.');
            } else {
                $path = $alt['path'] ?? $alt;
                $isRelation = $alt['is_relation'] ?? str_contains($path, '.');
            }

            if ($isRelation) {
                $relations[] = $path;
            } else {
                $fields[] = $path;
            }
        }

        $altParts = [];
        if (! empty($fields)) {
            $altParts[] = '<fg=gray>Fields:</> '.implode(', ', array_slice($fields, 0, 3));
        }
        if (! empty($relations)) {
            $altParts[] = '<fg=magenta>Relations:</> '.implode(', ', array_slice($relations, 0, 3));
        }

        if (! empty($altParts)) {
            $this->command->line('  <fg=cyan>Alternatives:</> '.implode(' | ', $altParts));
        }
    }

    private function askForCustomMapping(string $sourceColumn, string $modelClass, bool $hasPrevious = false, mixed $columnMeta = null): string|false
    {
        $this->command->newLine();
        $this->command->line('  <fg=cyan>Mapping column:</> <fg=yellow>'.$sourceColumn.'</>');

        $relations = $this->modelSelectionService->getModelRelations($modelClass);
        $fillableAttributes = $this->modelSelectionService->getAllModelAttributes($modelClass);

        $options = CustomMappingAction::options(count($relations), count($fillableAttributes), $hasPrevious);

        $action = select(
            label: '  How do you want to map this column?',
            options: $options,
            default: CustomMappingAction::Skip->value
        );

        return $this->processCustomMappingAction($action, $sourceColumn, $relations, $fillableAttributes, $modelClass, $columnMeta);
    }

    private function processCustomMappingAction(string $action, string $sourceColumn, array $relations, array $fillableAttributes, string $modelClass, mixed $columnMeta = null): string|false
    {
        $result = match ($action) {
            CustomMappingAction::Back->value => InteractiveCommand::Back->value,
            CustomMappingAction::Manual->value => $this->handleManualMapping($fillableAttributes),
            CustomMappingAction::Field->value => $this->handleFieldMapping($sourceColumn, $fillableAttributes),
            CustomMappingAction::Relation->value => $this->relationMapping()->handleRelationMapping($sourceColumn, $relations, $modelClass, $columnMeta),
            default => false,
        };

        // If sub-menu returned back, re-show the main menu
        if ($result === InteractiveCommand::Back->value && $action !== CustomMappingAction::Back->value) {
            return $this->askForCustomMapping($sourceColumn, $modelClass, true);
        }

        return $result;
    }

    private function handleManualMapping(array $fillableAttributes): string
    {
        if (! empty($fillableAttributes)) {
            $this->command->line('  <fg=gray>Available fields:</> '.implode(', ', $fillableAttributes));
        }

        $customPath = text(
            label: '  Enter field name (or relation.field for nested mapping)',
            placeholder: 'e.g., name or category.name',
            required: true,
            validate: fn ($value) => empty(trim($value)) ? 'Field name cannot be empty' : null
        );

        return trim($customPath);
    }

    private function handleFieldMapping(string $sourceColumn, array $fillableAttributes): string
    {
        if (empty($fillableAttributes)) {
            $this->command->warning('  No fields available. Please enter field name manually.');

            $customPath = text(
                label: '  Enter field name',
                required: true,
                validate: fn ($value) => empty(trim($value)) ? 'Field name cannot be empty' : null
            );

            return trim($customPath);
        }

        $fieldOptions = ['__back__' => '← Back'] + array_combine($fillableAttributes, $fillableAttributes);
        $selectedField = select(
            label: '  Select field for column \''.$sourceColumn.'\'',
            options: $fieldOptions,
            scroll: min(count($fieldOptions), 15)
        );

        if ($selectedField === '__back__') {
            return InteractiveCommand::Back->value;
        }

        return $selectedField;
    }

    /**
     * Check if mapping has duplicate handling configured.
     */
    private function isDuplicateHandlingConfigured(MappingDefinition $mapping): bool
    {
        $firstMapping = $mapping->mappings[0] ?? null;
        if ($firstMapping === null) {
            return false;
        }

        $options = $firstMapping->options ?? [];

        return ! empty($options['unique_key'])
            && ! empty($options['duplicate_strategy']);
    }
}
