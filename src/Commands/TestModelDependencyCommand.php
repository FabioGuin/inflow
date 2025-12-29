<?php

namespace InFlow\Commands;

use Illuminate\Console\Command;
use InFlow\Services\Mapping\ModelDependencyService;

/**
 * Test command for ModelDependencyService.
 *
 * Allows testing the dependency analysis step by step.
 */
class TestModelDependencyCommand extends Command
{
    protected $signature = 'inflow:test-dependency 
                            {model? : The model class to analyze}
                            {--find-roots : Find all root models in namespace}';

    protected $description = 'Test ModelDependencyService - analyze model dependencies';

    public function handle(
        ModelDependencyService $dependencyService
    ): int {
        if ($this->option('find-roots')) {
            return $this->handleFindRoots($dependencyService);
        }

        $modelClass = $this->argument('model') ?? 'App\\Models\\Author';

        $this->info("🔍 Analyzing dependencies for: {$modelClass}");
        $this->newLine();

        $analysis = $dependencyService->analyzeDependencies($modelClass);

        $this->line("📊 Analysis Results:");
        $this->newLine();

        $this->line("  BelongsTo Relations:");
        if (empty($analysis['belongsTo'])) {
            $this->line("    ✅ None (this is a root model)");
        } else {
            foreach ($analysis['belongsTo'] as $relationName => $relatedModel) {
                $this->line("    - {$relationName} → {$relatedModel}");
            }
        }

        $this->newLine();
        $this->line("  Is Root Model: " . ($analysis['isRoot'] ? '✅ Yes' : '❌ No'));

        $this->newLine();
        $this->line("  Required Dependencies:");
        if (empty($analysis['requiredDependencies'])) {
            $this->line("    ✅ None");
        } else {
            foreach ($analysis['requiredDependencies'] as $dependency) {
                $this->line("    - {$dependency}");
            }
        }

        $this->newLine();
        $this->line("  Optional Dependencies:");
        if (empty($analysis['optionalDependencies'])) {
            $this->line("    ✅ None");
        } else {
            foreach ($analysis['optionalDependencies'] as $dependency) {
                $this->line("    - {$dependency}");
            }
        }

        return Command::SUCCESS;
    }

    private function handleFindRoots(ModelDependencyService $dependencyService): int
    {
        $this->info("🔍 Finding root models in namespace: App\\Models");
        $this->newLine();

        $rootModels = $dependencyService->findRootModels('App\\Models');

        if (empty($rootModels)) {
            $this->warn("  No root models found.");
        } else {
            $count = count($rootModels);
            $this->line("  ✅ Root models found ({$count}):");
            $this->newLine();
            foreach ($rootModels as $modelClass) {
                $this->line("    - {$modelClass}");
            }
        }

        $this->newLine();
        $this->line("  📋 All models analyzed:");
        $allModels = $this->getAllModelsInNamespace('App\\Models');
        foreach ($allModels as $modelClass) {
            $isRoot = $dependencyService->isRootModel($modelClass);
            $icon = $isRoot ? '✅' : '❌';
            $this->line("    {$icon} {$modelClass}");
        }

        return Command::SUCCESS;
    }

    private function getAllModelsInNamespace(string $namespace): array
    {
        $models = [];
        $directory = app_path(str_replace('App\\', '', $namespace));

        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory.'/*.php');
        foreach ($files as $file) {
            $className = basename($file, '.php');
            $modelClass = $namespace.'\\'.$className;
            if (class_exists($modelClass) && is_subclass_of($modelClass, \Illuminate\Database\Eloquent\Model::class)) {
                $models[] = $modelClass;
            }
        }

        return $models;
    }
}

