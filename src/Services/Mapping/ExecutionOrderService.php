<?php

namespace InFlow\Services\Mapping;

/**
 * Service for suggesting execution order for models based on their dependencies.
 *
 * Analyzes model dependencies (BelongsTo relationships) to determine the correct
 * execution order, ensuring that models with dependencies are processed after
 * the models they depend on.
 */
readonly class ExecutionOrderService
{
    public function __construct(
        private ModelDependencyService $dependencyService
    ) {}

    /**
     * Suggest execution order for a list of models.
     *
     * @param  array<string>  $modelClasses  Array of model class names
     * @return array<string, int> Array of [model_class => execution_order]
     */
    public function suggestExecutionOrder(array $modelClasses): array
    {
        if (empty($modelClasses)) {
            return [];
        }

        // Build dependency graph
        $dependencies = $this->buildDependencyGraph($modelClasses);

        // Detect circular dependencies
        $circular = $this->detectCircularDependencies($dependencies);
        if (! empty($circular)) {
            // TODO: Handle circular dependencies (throw exception or log warning)
        }

        // Calculate execution order using topological sort
        $executionOrder = $this->topologicalSort($dependencies);

        return $executionOrder;
    }

    /**
     * Build dependency graph from model classes.
     *
     * @param  array<string>  $modelClasses  Array of model class names
     * @return array<string, array<string>> Graph where key is model, value is array of dependencies
     */
    private function buildDependencyGraph(array $modelClasses): array
    {
        $graph = [];

        foreach ($modelClasses as $modelClass) {
            $analysis = $this->dependencyService->analyzeDependencies($modelClass);
            $dependencies = [];

            // Only include dependencies that are in our model list
            foreach ($analysis['requiredDependencies'] as $dependency) {
                if (in_array($dependency, $modelClasses, true)) {
                    $dependencies[] = $dependency;
                }
            }

            $graph[$modelClass] = $dependencies;
        }

        return $graph;
    }

    /**
     * Detect circular dependencies in the graph.
     *
     * @param  array<string, array<string>>  $graph  Dependency graph
     * @return array<string> Array of models involved in circular dependencies
     */
    private function detectCircularDependencies(array $graph): array
    {
        $visited = [];
        $recStack = [];
        $circular = [];

        foreach (array_keys($graph) as $model) {
            if (! isset($visited[$model])) {
                $this->dfsForCircular($graph, $model, $visited, $recStack, $circular);
            }
        }

        return array_unique($circular);
    }

    /**
     * DFS helper for detecting circular dependencies.
     */
    private function dfsForCircular(
        array $graph,
        string $node,
        array &$visited,
        array &$recStack,
        array &$circular
    ): void {
        $visited[$node] = true;
        $recStack[$node] = true;

        foreach ($graph[$node] ?? [] as $dependency) {
            if (! isset($visited[$dependency])) {
                $this->dfsForCircular($graph, $dependency, $visited, $recStack, $circular);
            } elseif (isset($recStack[$dependency]) && $recStack[$dependency]) {
                // Circular dependency detected
                $circular[] = $node;
                $circular[] = $dependency;
            }
        }

        $recStack[$node] = false;
    }

    /**
     * Perform topological sort to determine execution order.
     *
     * @param  array<string, array<string>>  $graph  Dependency graph
     * @return array<string, int> Array of [model_class => execution_order]
     */
    private function topologicalSort(array $graph): array
    {
        $inDegree = [];
        $executionOrder = [];

        // Initialize in-degree for all nodes
        // In-degree = number of dependencies this model has
        foreach (array_keys($graph) as $model) {
            $inDegree[$model] = count($graph[$model] ?? []);
        }

        // Find all nodes with in-degree 0 (root models - no dependencies)
        $queue = [];
        foreach ($inDegree as $model => $degree) {
            if ($degree === 0) {
                $queue[] = $model;
            }
        }

        $currentOrder = 1;

        // Process nodes in topological order
        while (! empty($queue)) {
            $node = array_shift($queue);
            $executionOrder[$node] = $currentOrder;
            $currentOrder++;

            // Reduce in-degree of nodes that depend on this node
            // (they can now be processed since their dependency is done)
            foreach ($graph as $model => $dependencies) {
                if (in_array($node, $dependencies, true)) {
                    $inDegree[$model]--;
                    if ($inDegree[$model] === 0) {
                        $queue[] = $model;
                    }
                }
            }
        }

        // Handle remaining nodes (shouldn't happen if no circular deps)
        foreach ($inDegree as $model => $degree) {
            if (! isset($executionOrder[$model])) {
                // Assign a high order number for nodes that couldn't be sorted
                $executionOrder[$model] = $currentOrder;
                $currentOrder++;
            }
        }

        return $executionOrder;
    }

    /**
     * Validate execution order for a list of models.
     *
     * Checks if the provided execution order respects all dependencies.
     *
     * @param  array<string, int>  $executionOrder  Array of [model_class => execution_order]
     * @return array{valid: bool, errors: array<string>} Validation result
     */
    public function validateExecutionOrder(array $executionOrder): array
    {
        $errors = [];
        $modelClasses = array_keys($executionOrder);

        foreach ($modelClasses as $modelClass) {
            $analysis = $this->dependencyService->analyzeDependencies($modelClass);
            $modelOrder = $executionOrder[$modelClass];

            foreach ($analysis['requiredDependencies'] as $dependency) {
                if (! isset($executionOrder[$dependency])) {
                    // Dependency not in the mapping, skip
                    continue;
                }

                $dependencyOrder = $executionOrder[$dependency];

                if ($dependencyOrder >= $modelOrder) {
                    $errors[] = "Model {$modelClass} (order: {$modelOrder}) depends on {$dependency} (order: {$dependencyOrder}). Dependencies must have lower execution_order.";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}

