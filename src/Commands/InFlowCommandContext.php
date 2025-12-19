<?php

namespace InFlow\Commands;

use Illuminate\Contracts\Container\Container;
use InFlow\Commands\Interactions\MappingInteraction;
use InFlow\Commands\Interactions\TransformInteraction;
use InFlow\Commands\Interactions\ValidationInteraction;
use InFlow\ValueObjects\MappingDefinition;
use InFlow\ValueObjects\SourceSchema;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Thin adapter for pipeline pipes.
 *
 * Keeps InFlowCommand small by moving interaction orchestration into a dedicated class,
 * while still delegating all console I/O and option/argument access to the real Command.
 */
class InFlowCommandContext
{
    private ?MappingInteraction $mappingInteraction = null;

    private ?TransformInteraction $transformInteraction = null;

    private ?ValidationInteraction $validationInteraction = null;

    public function __construct(
        private readonly InFlowCommand $command,
        private readonly Container $container
    ) {}

    // ---- Console passthrough ----

    public function argument(string $key): mixed
    {
        return $this->command->argument($key);
    }

    public function option(string $key): mixed
    {
        return $this->command->option($key);
    }

    public function isQuiet(): bool
    {
        return $this->command->isQuiet();
    }

    public function getOutput(): OutputInterface
    {
        return $this->command->getOutput();
    }

    public function confirm(string $question, bool $default = true): bool
    {
        return \Laravel\Prompts\confirm(label: $question, default: $default, yes: 'y', no: 'n');
    }

    /**
     * @param  array<int|string, string>  $choices
     */
    public function choice(string $question, array $choices, mixed $default = null): mixed
    {
        return $this->command->choice($question, $choices, $default);
    }

    public function hasParameterOption(string $name, bool $onlyParams = false): bool
    {
        return $this->command->hasParameterOption($name, $onlyParams);
    }

    public function line(string $string, ?string $style = null, $verbosity = null): void
    {
        $this->command->line($string, $style, $verbosity);
    }

    public function newLine(int $count = 1): void
    {
        $this->command->newLine($count);
    }

    public function table(array $headers, array $rows, string $tableStyle = 'default', array $columnStyles = []): void
    {
        $this->command->table($headers, $rows, $tableStyle, $columnStyles);
    }

    public function flushOutput(): void
    {
        $this->command->flushOutput();
    }

    public function infoLine(string $message, bool $force = false): void
    {
        $this->command->infoLine($message, $force);
    }

    public function note(string $string, string $type = 'info'): void
    {
        $this->command->note($string, $type);
    }

    public function warning(string $string): void
    {
        $this->command->warning($string);
    }

    public function error(string $string): void
    {
        $this->command->error($string);
    }

    public function success(string $message): void
    {
        $this->command->success($message);
    }

    public function getOption(string $key): mixed
    {
        return $this->command->getOption($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSanitizerConfig(): array
    {
        return $this->command->getSanitizerConfig();
    }

    // ---- Interaction methods used by pipes ----

    public function getModelClass(): ?string
    {
        return $this->mappingInteraction()->getModelClass();
    }

    public function configureDuplicateHandling(MappingDefinition $mapping, string $modelClass): MappingDefinition
    {
        return $this->mappingInteraction()->configureDuplicateHandling($mapping, $modelClass);
    }

    public function detectUniqueKeys(string $modelClass, string $table): array
    {
        return $this->mappingInteraction()->detectUniqueKeys($modelClass, $table);
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
        return $this->mappingInteraction()->handleColumnMapping(
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
    }

    public function handleTransformSelection(
        string $sourceColumn,
        string $targetPath,
        array $suggestedTransforms,
        mixed $columnMeta,
        ?string $targetType = null,
        ?string $modelClass = null
    ): array {
        return $this->transformInteraction()->handleTransformSelection(
            $sourceColumn,
            $targetPath,
            $suggestedTransforms,
            $columnMeta,
            $targetType,
            $modelClass
        );
    }

    public function validateMappingBeforeExecution(MappingDefinition $mapping, SourceSchema $sourceSchema): bool|MappingDefinition
    {
        return $this->validationInteraction()->validateMappingBeforeExecution($mapping, $sourceSchema);
    }

    // ---- Lazy init ----

    private function mappingInteraction(): MappingInteraction
    {
        return $this->mappingInteraction ??= $this->container->makeWith(MappingInteraction::class, [
            'command' => $this->command,
        ]);
    }

    private function transformInteraction(): TransformInteraction
    {
        return $this->transformInteraction ??= $this->container->makeWith(TransformInteraction::class, [
            'command' => $this->command,
        ]);
    }

    private function validationInteraction(): ValidationInteraction
    {
        return $this->validationInteraction ??= $this->container->makeWith(ValidationInteraction::class, [
            'command' => $this->command,
            'mappingInteraction' => $this->mappingInteraction(),
        ]);
    }

    /**
     * Display a checkpoint and ask user whether to continue.
     *
     * @param  string  $stepName  Name of the completed step
     * @param  array<string, string>  $summary  Key-value pairs to display
     * @return string 'continue' or 'cancel'
     */
    public function checkpoint(string $stepName, array $summary = []): string
    {
        if ($this->isQuiet() || $this->option('no-interaction')) {
            return 'continue';
        }

        $this->newLine();
        $this->line('<fg=green>✓</> <fg=white;options=bold>'.$stepName.' completed</>');

        if (! empty($summary)) {
            foreach ($summary as $label => $value) {
                $this->line('  <fg=gray>'.$label.':</> <fg=white>'.$value.'</>');
            }
        }

        $this->newLine();

        return \Laravel\Prompts\select(
            label: '  Continue?',
            options: [
                'continue' => '▶ Continue to next step',
                'cancel' => '✕ Cancel import',
            ],
            default: 'continue'
        );
    }
}
