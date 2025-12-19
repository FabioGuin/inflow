<?php

namespace InFlow\Commands\Interactions;

use Illuminate\Support\Facades\DB;
use InFlow\Commands\InFlowCommand;
use InFlow\Enums\DuplicateStrategy;
use InFlow\Services\Core\InFlowConsoleServices;
use InFlow\ValueObjects\MappingDefinition;
use InFlow\ValueObjects\ModelMapping;

use function Laravel\Prompts\select;

/**
 * Handles duplicate handling configuration during mapping setup.
 */
class DuplicateHandlingInteraction
{
    public function __construct(
        private readonly InFlowCommand $command,
        private readonly InFlowConsoleServices $services
    ) {}

    /**
     * Configure duplicate handling for a mapping.
     */
    public function configure(MappingDefinition $mapping, string $modelClass): MappingDefinition
    {
        if ($this->command->isQuiet() || $this->command->option('no-interaction')) {
            return $this->autoConfigure($mapping, $modelClass);
        }

        return $this->interactiveConfigure($mapping, $modelClass);
    }

    /**
     * Detect unique keys from database schema.
     *
     * @return array<string>
     */
    public function detectUniqueKeys(string $modelClass, string $table): array
    {
        try {
            $model = new $modelClass;
            $primaryKey = $model->getKeyName();
            $uniqueIndexes = $this->extractUniqueIndexes($table, $primaryKey);

            return $this->combineUniqueKeys($uniqueIndexes, $primaryKey);
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', ['operation' => 'detectUniqueKeys', 'model' => $modelClass]);

            return [];
        }
    }

    /**
     * Extract unique indexes from table.
     *
     * @return array<string>
     */
    private function extractUniqueIndexes(string $table, ?string $primaryKey): array
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Non_unique = 0");
        $uniqueFields = [];

        foreach ($indexes as $index) {
            $column = $index->Column_name ?? null;
            if ($column && $column !== $primaryKey) {
                $uniqueFields[] = $column;
            }
        }

        return array_unique($uniqueFields);
    }

    /**
     * Combine unique keys with primary key.
     *
     * @return array<string>
     */
    private function combineUniqueKeys(array $uniqueIndexes, ?string $primaryKey): array
    {
        $uniqueKeys = $uniqueIndexes;

        if ($primaryKey !== null && ! in_array($primaryKey, $uniqueKeys, true)) {
            $uniqueKeys[] = $primaryKey;
        }

        return array_values(array_unique($uniqueKeys));
    }

    /**
     * Auto-configure duplicate handling (non-interactive mode).
     */
    private function autoConfigure(MappingDefinition $mapping, string $modelClass): MappingDefinition
    {
        try {
            $model = new $modelClass;
            $table = $model->getTable();
            $uniqueKeys = $this->detectUniqueKeys($modelClass, $table);

            if (! empty($uniqueKeys)) {
                return $this->applyConfig($mapping, $uniqueKeys[0], DuplicateStrategy::Update->value);
            }
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', ['operation' => 'autoConfigureDuplicateHandling']);
        }

        return $mapping;
    }

    /**
     * Interactive duplicate handling configuration.
     */
    private function interactiveConfigure(MappingDefinition $mapping, string $modelClass): MappingDefinition
    {
        try {
            $model = new $modelClass;
            $table = $model->getTable();
            $uniqueKeys = $this->detectUniqueKeys($modelClass, $table);

            if (empty($uniqueKeys)) {
                $this->command->warning('No unique keys detected. Duplicate handling not configured.');

                return $mapping;
            }

            $this->displayHeader($uniqueKeys);
            $uniqueKey = $this->promptForUniqueKey($uniqueKeys);
            $strategy = $this->promptForStrategy();

            return $this->applyConfig($mapping, $uniqueKey, $strategy);
        } catch (\Exception $e) {
            \inflow_report($e, 'warning', ['operation' => 'interactiveConfigureDuplicateHandling']);
            $this->command->warning('Failed to configure duplicate handling: '.$e->getMessage());

            return $mapping;
        }
    }

    /**
     * Display duplicate handling header.
     */
    private function displayHeader(array $uniqueKeys): void
    {
        $this->command->newLine();
        $this->command->line('<fg=cyan>Duplicate Handling Configuration</>');
        $this->command->line('  ──────────────────────────────────────');
        $this->command->line('  <fg=gray>Detected unique keys:</>');

        foreach ($uniqueKeys as $key) {
            $this->command->line('    <fg=yellow>•</> '.$key);
        }

        $this->command->newLine();
    }

    /**
     * Prompt for unique key selection.
     */
    private function promptForUniqueKey(array $uniqueKeys): string
    {
        $options = array_combine($uniqueKeys, $uniqueKeys);

        return select(
            label: '  Unique field for duplicate detection (skip/update/error)',
            options: $options,
            default: $uniqueKeys[0]
        );
    }

    /**
     * Prompt for duplicate strategy.
     */
    private function promptForStrategy(): string
    {
        return select(
            label: '  How to handle duplicate records?',
            options: [
                DuplicateStrategy::Update->value => DuplicateStrategy::Update->label(),
                DuplicateStrategy::Skip->value => DuplicateStrategy::Skip->label(),
                DuplicateStrategy::Error->value => DuplicateStrategy::Error->label(),
            ],
            default: DuplicateStrategy::Update->value
        );
    }

    /**
     * Apply duplicate handling configuration to mapping.
     */
    private function applyConfig(MappingDefinition $mapping, string $uniqueKey, string $strategy): MappingDefinition
    {
        $updatedMappings = [];

        foreach ($mapping->mappings as $modelMapping) {
            $options = $modelMapping->options;
            $options['unique_key'] = $uniqueKey;
            $options['duplicate_strategy'] = $strategy;

            $updatedMappings[] = new ModelMapping(
                modelClass: $modelMapping->modelClass,
                columns: $modelMapping->columns,
                options: $options
            );
        }

        return new MappingDefinition(
            mappings: $updatedMappings,
            sourceSchema: $mapping->sourceSchema
        );
    }
}

