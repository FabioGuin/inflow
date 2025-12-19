<?php

namespace InFlow\Commands\Pipes;

use Closure;
use InFlow\Commands\InFlowCommandContext;
use InFlow\Contracts\ProcessingPipeInterface;
use InFlow\Enums\ConfigKey;
use InFlow\Mappings\MappingBuilder;
use InFlow\Services\Core\ConfigurationResolver;
use InFlow\Services\Mapping\MappingGenerationService;
use InFlow\Services\Mapping\MappingProcessingService;
use InFlow\ValueObjects\MappingDefinition;
use InFlow\ValueObjects\ProcessingContext;
use InFlow\ValueObjects\SourceSchema;

use function Laravel\Prompts\confirm;

/**
 * Seventh step of the ETL pipeline: process mapping definition.
 *
 * Loads existing mapping, generates new mapping interactively, or uses
 * explicitly provided mapping file. Handles duplicate handling configuration.
 */
readonly class ProcessMappingPipe implements ProcessingPipeInterface
{
    public function __construct(
        private InFlowCommandContext $command,
        private ConfigurationResolver $configResolver,
        private MappingProcessingService $mappingProcessingService,
        private MappingGenerationService $mappingGenerationService,
        private MappingBuilder $mappingBuilder
    ) {}

    /**
     * Process mapping definition and update processing context.
     *
     * Loads existing mapping, generates new mapping, or uses explicitly
     * provided mapping file based on context and user input.
     *
     * @param  ProcessingContext  $context  The processing context containing source schema
     * @param  Closure  $next  The next pipe in the pipeline
     * @return ProcessingContext Updated context with mapping definition
     */
    public function handle(ProcessingContext $context, Closure $next): ProcessingContext
    {
        if ($context->cancelled) {
            return $next($context);
        }

        if ($context->reader === null || $context->sourceSchema === null) {
            $this->command->warning('Mapping skipped (no schema available)');

            $this->command->newLine();

            return $next($context);
        }

        $this->command->infoLine('<fg=blue>Step 7/9:</> <fg=gray>Processing mapping...</>');

        $mappingDefinition = $this->processMapping($context->sourceSchema);

        if ($mappingDefinition === null) {
            return $next($context->withCancelled());
        }

        $context = $context->withMappingDefinition($mappingDefinition);

        // Checkpoint after mapping configuration
        $checkpointResult = $this->showMappingCheckpoint($mappingDefinition);
        if ($checkpointResult === 'cancel') {
            return $next($context->withCancelled());
        }

        return $next($context);
    }

    /**
     * Show checkpoint after mapping is configured.
     *
     * @param  MappingDefinition  $mapping  The configured mapping
     * @return string 'continue' or 'cancel'
     */
    private function showMappingCheckpoint(MappingDefinition $mapping): string
    {
        $columnCount = 0;
        $relationCount = 0;
        $modelClass = '';

        foreach ($mapping->mappings as $modelMapping) {
            $modelClass = $modelMapping->modelClass;
            foreach ($modelMapping->columns as $column) {
                if (str_contains($column->targetPath, '.')) {
                    $relationCount++;
                } else {
                    $columnCount++;
                }
            }
        }

        return $this->command->checkpoint('Mapping configuration', [
            'Model' => class_basename($modelClass),
            'Direct fields' => (string) $columnCount,
            'Relation fields' => (string) $relationCount,
        ]);
    }

    /**
     * Process mapping definition.
     *
     * Separates business logic (loading, checking, saving mappings) from
     * presentation (prompts, output). Uses MappingProcessingService for business logic.
     *
     * @param  SourceSchema  $sourceSchema  The source schema
     * @return MappingDefinition|null The processed mapping or null if processing fails
     */
    private function processMapping(SourceSchema $sourceSchema): ?MappingDefinition
    {
        $mappingPath = $this->command->getOption(ConfigKey::Mapping->value);
        $modelClass = $this->command->getModelClass();

        if ($modelClass === null) {
            return null;
        }

        // If mapping file is explicitly provided, load it
        if ($mappingPath !== null) {
            return $this->loadExplicitMapping($mappingPath);
        }

        // Try to find existing mapping file for this model
        $existingMappingPath = $this->configResolver->findMappingForModel($modelClass);
        if ($existingMappingPath !== null) {
            $result = $this->handleExistingMapping($existingMappingPath, $modelClass);
            if ($result !== null) {
                return $result;
            }
        }

        // No existing mapping found - generate new one (will be auto-saved)
        $filePath = $this->command->argument('from');

        return $this->generateMapping($sourceSchema, $modelClass, $filePath);
    }

    /**
     * Load mapping from explicitly provided path.
     *
     * @param  string  $mappingPath  The path to the mapping file
     * @return MappingDefinition|null The loaded mapping or null if loading fails
     */
    private function loadExplicitMapping(string $mappingPath): ?MappingDefinition
    {
        try {
            // Business logic: load mapping
            $mapping = $this->mappingProcessingService->loadMapping($mappingPath);

            // Presentation: display success message
            $this->command->infoLine('<fg=green>✓ Mapping loaded from:</> <fg=yellow>'.$mappingPath.'</>');

            return $mapping;
        } catch (\Exception $e) {
            \inflow_report($e, 'error', ['operation' => 'loadMapping', 'path' => $mappingPath]);
            $this->command->error('Failed to load mapping: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Handle existing mapping file (prompt user and load/configure if needed).
     *
     * @param  string  $existingMappingPath  The path to the existing mapping file
     * @param  string  $modelClass  The model class name
     * @return MappingDefinition|null The loaded mapping or null if user wants to regenerate
     */
    private function handleExistingMapping(string $existingMappingPath, string $modelClass): ?MappingDefinition
    {
        // Presentation: ask user if they want to regenerate or use existing
        $useExisting = true;
        if (! $this->command->isQuiet() && ! $this->command->option('no-interaction')) {
            $this->command->line('<fg=cyan>Found existing mapping:</> <fg=yellow>'.$existingMappingPath.'</>');
            $useExisting = confirm(label: '  Use existing mapping?', default: false, yes: 'y', no: 'n');
        }

        if (! $useExisting) {
            // Presentation: user wants to regenerate
            $this->command->infoLine('<fg=cyan>→</> Regenerating mapping...');

            return null;
        }

        try {
            // Business logic: load mapping
            $mapping = $this->mappingProcessingService->loadMapping($existingMappingPath);

            // Presentation: display success message
            $this->command->infoLine('<fg=green>✓ Mapping loaded from:</> <fg=yellow>'.$existingMappingPath.'</> (auto-detected)');

            // Business logic: check if duplicate handling is configured
            if (! $this->mappingProcessingService->isDuplicateHandlingConfigured($mapping)) {
                // Configure duplicate handling
                $mapping = $this->configureAndSaveDuplicateHandling($mapping, $modelClass, $existingMappingPath);
            }

            return $mapping;
        } catch (\Exception $e) {
            // If loading fails, continue to generate new mapping
            \inflow_report($e, 'warning', ['operation' => 'loadExistingMapping', 'model' => $modelClass]);
            $this->command->warning('Failed to load existing mapping, generating new one...');

            return null;
        }
    }

    /**
     * Configure and save duplicate handling for a mapping.
     *
     * @param  MappingDefinition  $mapping  The mapping to configure
     * @param  string  $modelClass  The model class name
     * @param  string  $mappingPath  The path where to save the mapping
     * @return MappingDefinition The updated mapping
     */
    private function configureAndSaveDuplicateHandling(MappingDefinition $mapping, string $modelClass, string $mappingPath): MappingDefinition
    {
        // Business logic: configure duplicate handling
        $mapping = $this->command->configureDuplicateHandling($mapping, $modelClass);

        // Business logic: verify that options were actually configured
        if ($this->mappingProcessingService->isDuplicateHandlingConfigured($mapping)) {
            // Business logic: save updated mapping
            try {
                $this->mappingProcessingService->saveMapping($mapping, $mappingPath);

                // Presentation: display success message
                if (! $this->command->isQuiet()) {
                    $this->command->infoLine('  <fg=gray>→</> Duplicate handling configured and saved.');
                }
            } catch (\Exception $e) {
                \inflow_report($e, 'warning', ['operation' => 'saveDuplicateHandlingConfig']);
                $this->command->warning('Failed to save duplicate handling configuration: '.$e->getMessage());
            }
        } else {
            // Presentation: configuration failed - warn user
            $this->command->warning('Could not auto-configure duplicate handling. Please configure manually or regenerate mapping.');
        }

        return $mapping;
    }

    /**
     * Save generated mapping with guaranteed duplicate handling options.
     *
     * @param  MappingDefinition  $mapping  The mapping to save
     * @param  string  $modelClass  The model class name
     * @return MappingDefinition The saved mapping with guaranteed options
     */
    private function saveGeneratedMapping(MappingDefinition $mapping, string $modelClass): MappingDefinition
    {
        // Business logic: get save path
        $saveMappingPath = $this->mappingGenerationService->getMappingSavePath($modelClass);

        try {
            // Business logic: ensure duplicate handling options are present
            $finalMapping = $this->mappingGenerationService->ensureDuplicateHandlingOptions(
                $mapping,
                $modelClass,
                fn (string $modelClass, string $table) => $this->command->detectUniqueKeys($modelClass, $table)
            );

            // Business logic: verify options before saving
            if (! $this->mappingGenerationService->hasDuplicateHandlingOptions($finalMapping)) {
                // Presentation: warn user
                $this->command->warning('Could not configure duplicate handling options. Mapping saved without options.');
            }

            // Business logic: save mapping
            $this->mappingGenerationService->saveMapping($finalMapping, $saveMappingPath);

            // Presentation: display success message
            $this->command->infoLine('  <fg=gray>→</> Mapping saved to: <fg=yellow>'.$saveMappingPath.'</>');

            return $finalMapping;
        } catch (\Exception $e) {
            \inflow_report($e, 'error', ['operation' => 'saveMapping', 'path' => $saveMappingPath]);
            $this->command->error('  Failed to save mapping: '.$e->getMessage());

            return $mapping;
        }
    }

    /**
     * Generate mapping interactively.
     *
     * Separates business logic (mapping generation, history management) from
     * presentation (prompts, output). Uses MappingGenerationService and
     * MappingHistoryService for business logic.
     *
     * @param  SourceSchema  $sourceSchema  The source schema
     * @param  string  $modelClass  The model class name
     * @param  string|null  $filePath  The source file path
     * @return MappingDefinition|null The generated mapping or null if generation fails
     */
    private function generateMapping(SourceSchema $sourceSchema, string $modelClass, ?string $filePath = null): ?MappingDefinition
    {
        try {
            // Track mapping history for undo functionality
            $mappingHistory = [];
            $currentIndex = -1;

            $mapping = $this->mappingBuilder->autoMapInteractive(
                schema: $sourceSchema,
                modelClass: $modelClass,
                interactiveCallback: function ($sourceColumn, $suggestedPath, $confidence, $alternatives, $isRelation = false, $isArrayRelation = false, $columnMeta = null) use ($modelClass, &$mappingHistory, &$currentIndex) {
                    return $this->command->handleColumnMapping(
                        $sourceColumn,
                        $suggestedPath,
                        $confidence,
                        $alternatives,
                        $isRelation,
                        $modelClass,
                        $mappingHistory,
                        $currentIndex,
                        $isArrayRelation,
                        $columnMeta
                    );
                },
                transformCallback: function ($sourceColumn, $targetPath, $suggestedTransforms, $columnMeta, $targetType = null, $modelClassParam = null) use ($modelClass) {
                    return $this->command->handleTransformSelection($sourceColumn, $targetPath, $suggestedTransforms, $columnMeta, $targetType, $modelClassParam ?? $modelClass);
                }
            );

            // Presentation: display success message
            $this->command->success('Mapping generated successfully');
            $columnCount = count($mapping->mappings[0]->columns ?? []);
            $this->command->infoLine('  <fg=gray>→</> <fg=yellow>'.$columnCount.'</> column(s) mapped');

            // Business logic: generate name and description
            $mappingName = $this->mappingGenerationService->generateMappingName($modelClass, $filePath);
            $mappingDescription = $this->mappingGenerationService->generateMappingDescription($modelClass, $filePath);

            // Create new MappingDefinition with name and description
            $mapping = new MappingDefinition(
                mappings: $mapping->mappings,
                name: $mappingName,
                description: $mappingDescription,
                sourceSchema: $mapping->sourceSchema
            );

            // Business logic: configure duplicate handling
            $mapping = $this->command->configureDuplicateHandling($mapping, $modelClass);

            // Business logic: ensure options and save mapping
            return $this->saveGeneratedMapping($mapping, $modelClass);
        } catch (\Exception $e) {
            \inflow_report($e, 'error', ['operation' => 'generateMapping', 'model' => $modelClass]);
            $this->command->error('Failed to generate mapping: '.$e->getMessage());

            return null;
        }
    }
}
