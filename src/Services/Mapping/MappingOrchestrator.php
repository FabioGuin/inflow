<?php

namespace InFlow\Services\Mapping;

use InFlow\Commands\Interactions\MappingInteraction;
use InFlow\Commands\MakeMappingCommand;
use InFlow\Contracts\ReaderInterface;
use InFlow\Detectors\FormatDetector;
use InFlow\Presenters\Contracts\PresenterInterface;
use InFlow\Profilers\Profiler;
use InFlow\Readers\CsvReader;
use InFlow\Readers\ExcelReader;
use InFlow\Readers\JsonLinesReader;
use InFlow\Readers\XmlReader;
use InFlow\Services\Core\ConfigurationResolver;
use InFlow\Services\DataProcessing\SanitizationService;
use InFlow\Services\File\FileReaderService;
use InFlow\Services\File\ModelSelectionService;
use InFlow\Sources\FileSource;
use InFlow\Enums\UI\MessageType;
use InFlow\ValueObjects\File\DetectedFormat;
use InFlow\ValueObjects\Mapping\MappingContext;
use InFlow\ViewModels\MessageViewModel;

/**
 * Orchestrator for mapping creation workflow.
 *
 * Handles the complete flow of creating a mapping file:
 * 1. Load and analyze file (format detection, profiling)
 * 2. Select and validate target model
 * 3. Analyze model dependencies
 * 4. Create mapping interactively
 * 5. Save mapping file
 */
class MappingOrchestrator
{
    /**
     * Set the mapping interaction handler (injected by command).
     *
     * This allows the orchestrator to use interaction methods without
     * directly accessing the command, maintaining separation of concerns.
     */
    private ?MappingInteraction $interaction = null;

    public function __construct(
        private readonly FileReaderService $fileReader,
        private readonly FormatDetector $formatDetector,
        private readonly Profiler $profiler,
        private readonly SanitizationService $sanitizationService,
        private readonly ConfigurationResolver $configResolver,
        private readonly ModelSelectionService $modelSelectionService,
        private readonly ModelDependencyService $dependencyService,
        private readonly ExecutionOrderService $executionOrderService
    ) {}

    public function setInteraction(MappingInteraction $interaction): void
    {
        $this->interaction = $interaction;
    }

    /**
     * Process the mapping creation workflow from start to finish.
     *
     * @param  MakeMappingCommand  $command  The command instance for I/O
     * @param  MappingContext  $context  The initial mapping context
     * @param  PresenterInterface  $presenter  The presenter for output
     * @return MappingContext The updated context with results
     */
    public function process(MakeMappingCommand $command, MappingContext $context, PresenterInterface $presenter): MappingContext
    {
        // Step 1: Load file
        $context = $this->loadFile($context, $presenter);
        if ($context->cancelled || $context->source === null) {
            return $context;
        }

        // Step 2: Sanitize (if needed)
        if ($command->option('sanitize')) {
            $context = $this->sanitize($context, $presenter);
            if ($context->cancelled) {
                return $context;
            }
        }

        // Step 3: Detect format
        $context = $this->detectFormat($context, $presenter);
        if ($context->cancelled || $context->format === null) {
            return $context;
        }

        // Step 4: Create reader and profile data
        $context = $this->profileData($context, $presenter);
        if ($context->cancelled || $context->sourceSchema === null) {
            return $context;
        }

        // Step 5: Select and validate model
        $context = $this->selectModel($command, $context, $presenter);
        if ($context->cancelled || $context->modelClass === null) {
            return $context;
        }

        // Step 6: Analyze dependencies
        $context = $this->analyzeDependencies($context, $presenter);
        if ($context->cancelled) {
            return $context;
        }

        // Step 7: Create mapping interactively
        $context = $this->createMapping($command, $context, $presenter);
        if ($context->cancelled) {
            return $context;
        }

        // Step 8: Save mapping file
        $context = $this->saveMapping($command, $context, $presenter);

        return $context;
    }

    /**
     * Step 1: Load file and create FileSource.
     */
    private function loadFile(MappingContext $context, PresenterInterface $presenter): MappingContext
    {
        try {
            $source = FileSource::fromPath($context->filePath);

            return $context->withSource($source);
        } catch (\RuntimeException $e) {
            $presenter->presentMessage($this->createMessage('Failed to load file: '.$e->getMessage(), 'error'));

            return $context->withCancelled();
        }
    }

    /**
     * Step 2: Sanitize file content if needed.
     */
    private function sanitize(MappingContext $context, PresenterInterface $presenter): MappingContext
    {
        if ($context->source === null) {
            return $context;
        }

        try {
            $content = $this->fileReader->read($context->source);
            $sanitizerConfig = $this->configResolver->buildSanitizerConfig(fn () => null);
            [$sanitized] = $this->sanitizationService->sanitize($content, $sanitizerConfig);

            // Create new source from sanitized content
            $tempFile = sys_get_temp_dir().'/inflow_'.uniqid().'_'.basename($context->filePath);
            file_put_contents($tempFile, $sanitized);
            $source = FileSource::fromPath($tempFile);

            return $context->withSource($source);
        } catch (\Exception $e) {
            $presenter->presentMessage($this->createMessage('Sanitization failed: '.$e->getMessage(), 'warning'));

            return $context;
        }
    }

    /**
     * Step 3: Detect file format.
     */
    private function detectFormat(MappingContext $context, PresenterInterface $presenter): MappingContext
    {
        if ($context->source === null) {
            return $context;
        }

        try {
            $format = $this->formatDetector->detect($context->source);

            return $context->withFormat($format);
        } catch (\Exception $e) {
            $presenter->presentMessage($this->createMessage('Format detection failed: '.$e->getMessage(), 'error'));

            return $context->withCancelled();
        }
    }

    /**
     * Step 4: Create reader and profile data.
     */
    private function profileData(MappingContext $context, PresenterInterface $presenter): MappingContext
    {
        if ($context->source === null || $context->format === null) {
            return $context;
        }

        $reader = $this->createReader($context->source, $context->format);
        if ($reader === null) {
            $presenter->presentMessage($this->createMessage("Unsupported file format: {$context->format->type->value}", 'error'));

            return $context->withCancelled();
        }

        try {
            $reader->rewind();
            $result = $this->profiler->profile($reader);
            $schema = $result['schema'];

            return $context
                ->withReader($reader)
                ->withSourceSchema($schema);
        } catch (\Exception $e) {
            $presenter->presentMessage($this->createMessage('Profiling failed: '.$e->getMessage(), 'error'));

            return $context->withCancelled();
        }
    }

    /**
     * Step 5: Select and validate target model.
     */
    private function selectModel(MakeMappingCommand $command, MappingContext $context, PresenterInterface $presenter): MappingContext
    {
        $modelClass = $command->argument('model');

        // If model not provided, prompt interactively
        if ($modelClass === null) {
            if ($this->interaction === null) {
                $presenter->presentMessage($this->createMessage('Model selection requires interaction handler', 'error'));

                return $context->withCancelled();
            }

            $modelClass = $this->interaction->promptModelSelection();
            if ($modelClass === null) {
                $presenter->presentMessage($this->createMessage('Model selection cancelled', 'error'));

                return $context->withCancelled();
            }
        }

        $normalizedModel = $this->modelSelectionService->normalizeModelClass($modelClass);
        $validationError = $this->modelSelectionService->validateModelClass($normalizedModel);

        if ($validationError !== null) {
            $presenter->presentMessage($this->createMessage("Model validation failed: {$validationError}", 'error'));

            return $context->withCancelled();
        }

        return $context->withModelClass($normalizedModel);
    }

    /**
     * Step 6: Analyze model dependencies.
     */
    private function analyzeDependencies(MappingContext $context, PresenterInterface $presenter): MappingContext
    {
        if ($context->modelClass === null) {
            return $context;
        }

        $analysis = $this->dependencyService->analyzeDependencies($context->modelClass);

        return $context->withDependencyAnalysis($analysis);
    }

    /**
     * Step 7: Create mapping interactively.
     */
    private function createMapping(MakeMappingCommand $command, MappingContext $context, PresenterInterface $presenter): MappingContext
    {
        if ($context->modelClass === null || $context->sourceSchema === null) {
            return $context;
        }

        // Prompt user to map columns interactively (no auto-matching)
        $mappingResult = ['main' => [], 'related' => []];
        if ($this->interaction !== null) {
            $mappingResult = $this->interaction->promptColumnMappings($context->sourceSchema, $context->modelClass);
        } else {
            // Fallback: if no interaction handler, return empty (user must provide mapping manually)
            $presenter->presentMessage($this->createMessage('Column mapping requires interaction handler', 'error'));

            return $context->withCancelled();
        }

        if (empty($mappingResult['main']) && empty($mappingResult['related'])) {
            $presenter->presentMessage($this->createMessage('No columns mapped. Mapping creation cancelled.', 'warning'));

            return $context->withCancelled();
        }

        // Build all mappings (main + related models)
        $allMappings = [];

        // Main model mapping (always execution_order: 1)
        $mainExecutionOrder = 1;

        if (! empty($mappingResult['main'])) {
            $allMappings[] = [
                'model' => $context->modelClass,
                'execution_order' => $mainExecutionOrder,
                'type' => 'model',
                'columns' => $mappingResult['main'],
            ];
        }

        // Related model mappings
        $executionOrders = [];
        if (! empty($mappingResult['related'])) {
            // Determine execution order for related models
            $modelClasses = array_merge(
                [$context->modelClass],
                array_keys($mappingResult['related'])
            );

            // Build dependency graph including nested relations from mappings
            $nestedDependencies = $this->extractNestedDependencies($mappingResult['related'], $context->modelClass);
            
            try {
                $executionOrders = $this->executionOrderService->suggestExecutionOrder($modelClasses);
                
                // Apply nested dependencies: if Book has tags.*.name, Tag depends on Book
                foreach ($nestedDependencies as $nestedModel => $parentModel) {
                    if (isset($executionOrders[$parentModel]) && isset($executionOrders[$nestedModel])) {
                        // Ensure nested model has higher execution_order than parent
                        if ($executionOrders[$nestedModel] <= $executionOrders[$parentModel]) {
                            $executionOrders[$nestedModel] = $executionOrders[$parentModel] + 1;
                        }
                    }
                }
            } catch (\Exception $e) {
                // If circular dependency or error, use simple incremental order
                $order = $mainExecutionOrder + 1;
                foreach (array_keys($mappingResult['related']) as $relatedModelClass) {
                    $executionOrders[$relatedModelClass] = $order++;
                }
                
                // Apply nested dependencies
                foreach ($nestedDependencies as $nestedModel => $parentModel) {
                    if (isset($executionOrders[$parentModel]) && isset($executionOrders[$nestedModel])) {
                        if ($executionOrders[$nestedModel] <= $executionOrders[$parentModel]) {
                            $executionOrders[$nestedModel] = $executionOrders[$parentModel] + 1;
                        }
                    }
                }
            }

            foreach ($mappingResult['related'] as $relatedModelClass => $relatedMapping) {
                $executionOrder = $executionOrders[$relatedModelClass] ?? ($mainExecutionOrder + 1);
                // Update execution_order in relatedMapping for summary
                $mappingResult['related'][$relatedModelClass]['execution_order'] = $executionOrder;
                
                $allMappings[] = [
                    'model' => $relatedModelClass,
                    'execution_order' => $executionOrder,
                    'type' => 'model',
                    'columns' => $relatedMapping['columns'],
                ];
            }
        }

        // Build mapping data structure
        $mappingData = [
            'version' => '1.0',
            'name' => class_basename($context->modelClass).' - '.basename($context->filePath),
            'description' => "Mapping for {$context->modelClass} from ".basename($context->filePath),
            'source_schema' => $this->convertSourceSchemaToArray($context->sourceSchema),
            'flow_config' => $this->buildFlowConfig($context, $command),
            'mappings' => $allMappings,
        ];

        $totalColumns = count($mappingResult['main']) + array_sum(array_map(fn ($m) => count($m['columns']), $mappingResult['related']));
        $totalMappings = count($allMappings);

        // Show final summary with correct execution orders
        if ($this->interaction !== null) {
            $this->interaction->showFinalMappingSummary($mappingResult['main'], $mappingResult['related'], $context->modelClass, $executionOrders ?? []);
        }

        $presenter->presentMessage($this->createMessage(
            "Mapping created: {$totalColumns} column(s) mapped across {$totalMappings} model(s)",
            'success'
        ));

        return $context->withMappingData($mappingData);
    }

    /**
     * Extract nested dependencies from mappings.
     * 
     * If Tag mapping has "tags.*.name" and Tag has BelongsToMany with Book,
     * then Tag depends on Book (Tag should be executed after Book).
     * 
     * @param  array<string, array{model: string, columns: array}>  $relatedMappings
     * @param  string  $rootModelClass
     * @return array<string, string> Map of [nested_model => parent_model]
     */
    private function extractNestedDependencies(array $relatedMappings, string $rootModelClass): array
    {
        $dependencies = [];
        
        // Check each model's mappings for nested relations
        foreach ($relatedMappings as $nestedModelClass => $nestedMapping) {
            // Check if this model has mappings with relation targets (e.g., "tags.*.name")
            $hasNestedRelationTarget = false;
            $nestedRelationName = null;
            
            foreach ($nestedMapping['columns'] as $column) {
                $target = $column['target'] ?? '';
                
                // Check if target is a relation (e.g., "tags.*.name")
                if (preg_match('/^([^.*]+)\.\*\./', $target, $matches)) {
                    $hasNestedRelationTarget = true;
                    $nestedRelationName = $matches[1];
                    break;
                }
            }
            
            if ($hasNestedRelationTarget && $nestedRelationName !== null) {
                // Find which model has this relation pointing to nestedModelClass
                // Example: Book has relation "tags" pointing to Tag
                foreach ($relatedMappings as $parentModelClass => $parentMapping) {
                    if ($parentModelClass === $nestedModelClass) {
                        continue; // Skip self
                    }
                    
                    try {
                        $parentModel = new $parentModelClass;
                        if (method_exists($parentModel, $nestedRelationName)) {
                            $relation = $parentModel->$nestedRelationName();
                            $relatedModelClass = get_class($relation->getRelated());
                            
                            // If parent model's relation points to nested model, nested depends on parent
                            if ($relatedModelClass === $nestedModelClass) {
                                $dependencies[$nestedModelClass] = $parentModelClass;
                                break;
                            }
                        }
                    } catch (\Exception $e) {
                        // Skip if we can't determine the relation
                    }
                }
            }
        }
        
        return $dependencies;
    }

    /**
     * Step 8: Save mapping file.
     */
    private function saveMapping(MakeMappingCommand $command, MappingContext $context, PresenterInterface $presenter): MappingContext
    {
        if ($context->mappingData === null) {
            $presenter->presentMessage($this->createMessage('No mapping data to save', 'error'));

            return $context->withCancelled();
        }

        // Determine output path
        $outputPath = $command->option('output');
        if ($outputPath === null && $context->modelClass !== null) {
            $modelName = class_basename($context->modelClass);
            $outputPath = "mappings/{$modelName}.json";
        }

        // If still no output path, prompt interactively
        if ($outputPath === null) {
            if ($this->interaction !== null) {
                $outputPath = $this->interaction->promptOutputPath();
                if ($outputPath === null) {
                    $presenter->presentMessage($this->createMessage('Output path required', 'error'));

                    return $context->withCancelled();
                }
            } else {
                $presenter->presentMessage($this->createMessage('Cannot determine output path for mapping file', 'error'));

                return $context->withCancelled();
            }
        }

        // Check if file exists and --force not set
        if (file_exists($outputPath) && ! $command->option('force')) {
            // Prompt for confirmation if interaction handler available
            if ($this->interaction !== null) {
                if (! $this->interaction->promptOverwriteConfirmation($outputPath)) {
                    $presenter->presentMessage($this->createMessage('Mapping creation cancelled', 'warning'));

                    return $context->withCancelled();
                }
            } else {
                $presenter->presentMessage($this->createMessage("Mapping file already exists: {$outputPath}. Use --force to overwrite.", 'error'));

                return $context->withCancelled();
            }
        }

        // Ensure directory exists
        $directory = dirname($outputPath);
        if ($directory !== '.' && ! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Save mapping file
        try {
            $json = json_encode($context->mappingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new \RuntimeException('Failed to encode mapping to JSON: '.json_last_error_msg());
            }

            if (file_put_contents($outputPath, $json) === false) {
                throw new \RuntimeException("Failed to write mapping file: {$outputPath}");
            }

            $presenter->presentMessage($this->createMessage("Mapping saved to: {$outputPath}", 'success'));

            return $context->withOutputPath($outputPath);
        } catch (\Exception $e) {
            $presenter->presentMessage($this->createMessage("Failed to save mapping: {$e->getMessage()}", 'error'));

            return $context->withCancelled();
        }
    }

    /**
     * Create reader based on format.
     */
    private function createReader(FileSource $source, DetectedFormat $format): ?ReaderInterface
    {
        return match ($format->type->value) {
            'csv' => new CsvReader($source, $format),
            'xlsx', 'xls' => new ExcelReader($source, $format),
            'json' => new JsonLinesReader($source),
            'xml' => new XmlReader($source),
            default => null,
        };
    }

    /**
     * Convert SourceSchema to array format for JSON.
     */
    private function convertSourceSchemaToArray(?\InFlow\ValueObjects\Data\SourceSchema $schema): array
    {
        if ($schema === null) {
            return ['columns' => [], 'total_rows' => 0];
        }

        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = [
                'name' => $column->name,
                'type' => $column->type->value,
                'null_count' => $column->nullCount,
                'unique_count' => $column->uniqueCount,
                'min' => $column->min,
                'max' => $column->max,
                'examples' => $column->examples,
            ];
        }

        return [
            'columns' => $columns,
            'total_rows' => $schema->totalRows,
        ];
    }

    /**
     * Build flow_config from context.
     *
     * @param  MakeMappingCommand  $command  Command instance to check for --sanitize-on-run option
     */
    private function buildFlowConfig(MappingContext $context, MakeMappingCommand $command): array
    {
        $flowConfig = [];

        // Add format config if available
        if ($context->format !== null) {
            $flowConfig['format'] = [
                'type' => $context->format->type->value,
                'delimiter' => $context->format->delimiter,
                'quote_char' => $context->format->quoteChar,
                'has_header' => $context->format->hasHeader,
                'encoding' => $context->format->encoding,
            ];
        }

        // Add sanitizer config if --sanitize-on-run is set (for recurring processes)
        if ($this->shouldIncludeSanitizerInFlowConfig($command)) {
            $flowConfig['sanitizer'] = [
                'enabled' => true,
                'remove_bom' => true,
                'normalize_newlines' => true,
                'remove_control_chars' => true,
                'newline_format' => 'lf',
            ];
        }

        // Add execution config with defaults
        $flowConfig['execution'] = [
            'chunk_size' => 1000,
            'error_policy' => 'continue',
            'skip_empty_rows' => true,
            'truncate_long_fields' => true,
        ];

        return $flowConfig;
    }

    /**
     * Check if sanitizer should be included in flow_config for recurring processes.
     */
    private function shouldIncludeSanitizerInFlowConfig(MakeMappingCommand $command): bool
    {
        return $command->option('sanitize-on-run') === true;
    }

    /**
     * Create a MessageViewModel for presenter.
     */
    private function createMessage(string $message, string $type): MessageViewModel
    {
        $messageType = match ($type) {
            'error' => MessageType::Error,
            'warning' => MessageType::Warning,
            'success' => MessageType::Success,
            default => MessageType::Info,
        };

        return new MessageViewModel($message, $messageType);
    }
}

