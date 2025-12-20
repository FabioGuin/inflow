<?php

namespace InFlow\Commands\Interactions;

use InFlow\Commands\InFlowCommand;
use InFlow\Services\Mapping\ModelCastService;
use InFlow\Services\Mapping\TransformFormatterService;
use InFlow\Services\Mapping\TransformSelectionService;

use function Laravel\Prompts\multiselect;

readonly class TransformInteraction
{
    public function __construct(
        private InFlowCommand $command,
        private TransformSelectionService $transformSelectionService,
        private TransformFormatterService $transformFormatterService,
        private ModelCastService $modelCastService
    ) {}

    public function handleTransformSelection(
        string $sourceColumn,
        string $targetPath,
        array $suggestedTransforms,
        mixed $columnMeta,
        ?string $targetType = null,
        ?string $modelClass = null
    ): array {
        if ($this->command->isQuiet() || $this->command->option('no-interaction')) {
            return $suggestedTransforms;
        }

        $this->command->line('');
        $this->command->line("  <fg=cyan>Transforms for</> <fg=yellow>{$sourceColumn}</> → <fg=green>{$targetPath}</>");

        $suggestedDisplay = $suggestedTransforms ? implode(', ', $suggestedTransforms) : 'none';
        $this->command->line("  <fg=gray>Suggested:</> {$suggestedDisplay}");

        // Get model cast info to show user what type is expected
        $castInfo = null;
        $hasCast = false;
        $fromDatabase = false;
        if ($modelClass !== null) {
            $castInfo = $this->modelCastService->getCastInfo($modelClass, $targetPath);
            $hasCast = $castInfo['type'] !== null;
            $fromDatabase = $castInfo['fromDatabase'] ?? false;

            if ($hasCast) {
                $castDisplay = $castInfo['precision'] !== null
                    ? "{$castInfo['type']}:{$castInfo['precision']}"
                    : $castInfo['type'];
                $sourceLabel = $fromDatabase ? 'DB column type' : 'Model cast';
                $this->command->line("  <fg=gray>{$sourceLabel}:</> <fg=cyan>{$castDisplay}</>".($fromDatabase ? ' <fg=gray>(from database)</>' : ''));
            } else {
                $this->command->line('  <fg=gray>Model cast:</> <fg=yellow>not defined</>');
            }
        }

        $this->command->flushOutput();

        // Use target field type to determine available transforms
        // Available transforms are filtered based on model cast type (if defined)
        // Also use DB type when available to filter transforms (e.g., cast=decimal but DB=int)
        $dbType = $castInfo['dbType'] ?? null;
        $transformTypes = $this->transformSelectionService->getAvailableTransformTypes($columnMeta, $targetType, $dbType);
        $castType = $this->transformSelectionService->getCastTypeForTarget($targetType);

        $availableTransforms = $this->transformFormatterService->formatForDisplay($transformTypes, $castType);

        // Show informational message about available transforms
        if ($hasCast && empty($availableTransforms)) {
            $sourceLabel = $fromDatabase ? 'database column type' : 'model cast';
            $this->command->line('  <fg=gray>Note: No transforms available for '.$sourceLabel.' "'.$castInfo['type'].'". Modify model cast to see more options.</>');
        } elseif ($hasCast && count($availableTransforms) > 0) {
            $sourceLabel = $fromDatabase ? 'database column type' : 'model cast';
            $this->command->line('  <fg=gray>Available transforms are filtered based on '.$sourceLabel.'. Modify model cast to see other options.</>');
        } elseif (! $hasCast && $modelClass !== null) {
            $sourceType = $columnMeta !== null ? $columnMeta->type->value : 'string';
            $this->command->line("  <fg=gray>Available transforms are based on source column type ({$sourceType}). Define a model cast or check database schema to see type-specific transforms.</>");
        }

        $selectedTransforms = $this->selectTransforms($sourceColumn, $suggestedTransforms, $availableTransforms);

        return $selectedTransforms;
    }

    private function selectTransforms(string $sourceColumn, array $suggestedTransforms, array $availableTransforms): array
    {
        if ($this->command->isQuiet() || $this->command->option('no-interaction')) {
            return $suggestedTransforms;
        }

        $options = $this->transformSelectionService->buildOptionsWithSuggestions($availableTransforms, $suggestedTransforms);
        $default = $this->transformSelectionService->getDefaultTransforms($options, $suggestedTransforms);

        $selected = multiselect(
            label: "  Select transforms for {$sourceColumn}",
            options: $options,
            default: $default,
            scroll: 10
        );

        return $this->transformSelectionService->processSelectedTransforms(
            $selected,
            fn (string $label, ?string $hint, array $examples, ?string $default) => $this->askForInput($label, $hint, $examples, $default)
        );
    }

    /**
     * Generic input prompt for interactive transforms.
     */
    private function askForInput(string $label, ?string $hint, array $examples, ?string $default): ?string
    {
        $this->command->line('');

        if ($hint !== null) {
            $this->command->line("  <fg=gray>{$hint}</>");
        }

        if (! empty($examples)) {
            $this->command->line('  <fg=gray>Examples: '.implode(', ', $examples).'</>');
        }

        $prompt = "  {$label}";
        if ($default !== null) {
            $prompt .= " [{$default}]";
        }

        return $this->command->askWithCancel($prompt.' (or "cancel" to skip):') ?? $default;
    }
}
