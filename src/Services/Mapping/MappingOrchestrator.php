<?php

namespace InFlow\Services\Mapping;

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
readonly class MappingOrchestrator
{
    public function __construct(
        private FileReaderService $fileReader,
        private FormatDetector $formatDetector,
        private Profiler $profiler,
        private SanitizationService $sanitizationService,
        private ConfigurationResolver $configResolver,
        private ModelSelectionService $modelSelectionService,
        private ModelDependencyService $dependencyService,
        private ExecutionOrderService $executionOrderService
    ) {}

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
        if ($modelClass === null) {
            // TODO: Interactive model selection
            $presenter->presentMessage($this->createMessage('Model selection not yet implemented. Please provide model as argument.', 'error'));

            return $context->withCancelled();
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
        // TODO: Implement interactive mapping creation
        $presenter->presentMessage($this->createMessage('Mapping creation not yet implemented', 'warning'));

        return $context;
    }

    /**
     * Step 8: Save mapping file.
     */
    private function saveMapping(MakeMappingCommand $command, MappingContext $context, PresenterInterface $presenter): MappingContext
    {
        // Determine output path
        $outputPath = $command->option('output');
        if ($outputPath === null && $context->modelClass !== null) {
            $modelName = class_basename($context->modelClass);
            $outputPath = "mappings/{$modelName}.json";
        }

        if ($outputPath === null) {
            $presenter->presentMessage($this->createMessage('Cannot determine output path for mapping file', 'error'));

            return $context->withCancelled();
        }

        // Check if file exists and --force not set
        if (file_exists($outputPath) && ! $command->option('force')) {
            $presenter->presentMessage($this->createMessage("Mapping file already exists: {$outputPath}. Use --force to overwrite.", 'error'));

            return $context->withCancelled();
        }

        // TODO: Implement actual mapping data creation and saving
        $presenter->presentMessage($this->createMessage('Mapping saving not yet implemented', 'warning'));

        return $context->withOutputPath($outputPath);
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

