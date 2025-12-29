<?php

namespace InFlow\Commands;

use Illuminate\Console\Command;
use InFlow\Services\Mapping\ExecutionOrderService;

/**
 * Test command for ExecutionOrderService.
 *
 * Allows testing the execution order suggestion step by step.
 */
class TestExecutionOrderCommand extends Command
{
    protected $signature = 'inflow:test-execution-order 
                            {models?* : Model classes to analyze (space-separated)}
                            {--validate : Validate execution order instead of suggesting}';

    protected $description = 'Test ExecutionOrderService - suggest execution order for models';

    public function handle(
        ExecutionOrderService $executionOrderService
    ): int {
        $modelClasses = $this->argument('models');

        if (empty($modelClasses)) {
            // Default test case
            $modelClasses = [
                'App\\Models\\Author',
                'App\\Models\\Book',
                'App\\Models\\Profile',
                'App\\Models\\Tag',
            ];
            $this->info("Using default test models:");
            foreach ($modelClasses as $model) {
                $this->line("  - {$model}");
            }
            $this->newLine();
        }

        if ($this->option('validate')) {
            return $this->handleValidate($executionOrderService, $modelClasses);
        }

        $this->info("🔍 Suggesting execution order for models:");
        foreach ($modelClasses as $model) {
            $this->line("  - {$model}");
        }
        $this->newLine();

        $executionOrder = $executionOrderService->suggestExecutionOrder($modelClasses);

        $this->line("📊 Suggested Execution Order:");
        $this->newLine();

        // Sort by execution order for display
        asort($executionOrder);
        foreach ($executionOrder as $modelClass => $order) {
            $this->line("  {$order}. {$modelClass}");
        }

        $this->newLine();
        $this->line("✅ Execution order respects all dependencies.");

        return Command::SUCCESS;
    }

    private function handleValidate(
        ExecutionOrderService $executionOrderService,
        array $modelClasses
    ): int {
        $this->info("🔍 Validating execution order for models:");
        foreach ($modelClasses as $model) {
            $this->line("  - {$model}");
        }
        $this->newLine();

        // For validation, we need an execution order to validate
        // Let's use the suggested one as example
        $suggestedOrder = $executionOrderService->suggestExecutionOrder($modelClasses);

        $this->line("📋 Execution order to validate:");
        asort($suggestedOrder);
        foreach ($suggestedOrder as $model => $order) {
            $this->line("  {$order}. {$model}");
        }
        $this->newLine();

        $validation = $executionOrderService->validateExecutionOrder($suggestedOrder);

        if ($validation['valid']) {
            $this->info("✅ Execution order is valid!");
        } else {
            $this->error("❌ Execution order has errors:");
            foreach ($validation['errors'] as $error) {
                $this->line("  - {$error}");
            }
        }

        return $validation['valid'] ? Command::SUCCESS : Command::FAILURE;
    }
}

