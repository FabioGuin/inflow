<?php

namespace InFlow;

use Illuminate\Support\ServiceProvider;

class InFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerCoreServices();
        $this->registerFileServices();
        $this->registerFormatterServices();
        $this->registerDataProcessingServices();
        $this->registerMappingServices();
        $this->registerLoadingServices();
    }

    /**
     * Register core configuration and resolution services.
     */
    private function registerCoreServices(): void
    {
        $this->app->singleton(\InFlow\Services\Core\ConfigurationResolver::class);
        $this->app->singleton(\InFlow\Services\Core\FlowBuilderService::class);
        $this->app->singleton(\InFlow\Services\Core\FlowExecutionService::class);
        $this->app->singleton(\InFlow\Services\Core\FlowRunBuilderService::class);
        $this->app->singleton(\InFlow\Services\Core\FlowEventService::class);
        $this->app->singleton(\InFlow\Services\Core\InFlowConsoleServices::class);
        $this->app->singleton(\InFlow\Commands\InFlowCommandContextFactory::class);
        $this->app->singleton(\InFlow\Services\Core\InFlowPipelineRunner::class);
    }

    /**
     * Register file-related services (reading, writing, selection).
     */
    private function registerFileServices(): void
    {
        $this->app->singleton(\InFlow\Services\File\FileReaderService::class);
        $this->app->singleton(\InFlow\Services\File\FileWriterService::class);
        $this->app->singleton(\InFlow\Services\File\FileSelectionService::class);
        $this->app->singleton(\InFlow\Services\File\ModelSelectionService::class);
    }

    /**
     * Register formatter services for display and output.
     */
    private function registerFormatterServices(): void
    {
        $this->app->singleton(\InFlow\Services\Formatter\ReportFormatterService::class);
        $this->app->singleton(\InFlow\Services\Formatter\FormatFormatterService::class);
        $this->app->singleton(\InFlow\Services\Formatter\DataPreviewFormatterService::class);
        $this->app->singleton(\InFlow\Services\Formatter\SchemaFormatterService::class);
        $this->app->singleton(\InFlow\Services\Formatter\QualityReportFormatterService::class);
        $this->app->singleton(\InFlow\Services\Formatter\SummaryFormatterService::class);
        $this->app->singleton(\InFlow\Services\Formatter\ProgressFormatterService::class);
        $this->app->singleton(\InFlow\Services\Formatter\FlowRunResultsFormatterService::class);
        $this->app->singleton(\InFlow\Services\Formatter\MappingFormatterService::class);
        $this->app->singleton(\InFlow\Services\Formatter\FlowWarningFormatterService::class);
    }

    /**
     * Register data processing services (sanitization, detection, profiling, preview).
     */
    private function registerDataProcessingServices(): void
    {
        $this->app->singleton(\InFlow\Sanitizers\RawSanitizer::class);
        $this->app->singleton(\InFlow\Services\DataProcessing\SanitizationService::class);
        $this->app->singleton(\InFlow\Services\DataProcessing\ContentUtilityService::class);
        $this->app->singleton(\InFlow\Detectors\FormatDetector::class);
        $this->app->singleton(\InFlow\Profilers\Profiler::class);
        $this->app->singleton(\InFlow\Services\DataProcessing\DataPreviewService::class);
        $this->app->singleton(\InFlow\Transforms\TransformEngine::class);
    }

    /**
     * Register mapping-related services.
     */
    private function registerMappingServices(): void
    {
        $this->app->singleton(\InFlow\Mappings\MappingSerializer::class);
        $this->app->singleton(\InFlow\Mappings\MappingBuilder::class);
        $this->app->singleton(\InFlow\Mappings\MappingValidator::class);
        $this->app->singleton(\InFlow\Services\Mapping\MappingProcessingService::class);
        $this->app->singleton(\InFlow\Services\Mapping\MappingGenerationService::class);
        $this->app->singleton(\InFlow\Services\Mapping\MappingHistoryService::class);
        $this->app->singleton(\InFlow\Services\Mapping\TransformSelectionService::class);
        $this->app->singleton(\InFlow\Services\Mapping\TransformFormatterService::class);
        $this->app->singleton(\InFlow\Services\Mapping\MappingValidationService::class);
        $this->app->singleton(\InFlow\Services\Mapping\ModelCastService::class);
        $this->app->singleton(\InFlow\Services\Mapping\MappingDependencyValidator::class);
    }

    /**
     * Register loading-related services.
     */
    private function registerLoadingServices(): void
    {
        $this->app->singleton(\InFlow\Services\Loading\ColumnValueService::class);
        $this->app->singleton(\InFlow\Services\Loading\ColumnValidationService::class);
        $this->app->singleton(\InFlow\Services\Loading\RelationTypeService::class);
        $this->app->singleton(\InFlow\Services\Loading\RelationLookupService::class);
        $this->app->singleton(\InFlow\Services\Loading\ModelPersistenceService::class);
        $this->app->singleton(\InFlow\Services\Loading\Strategies\RelationSyncStrategyFactory::class);
        $this->app->singleton(\InFlow\Services\Loading\AttributeGroupingService::class);
        $this->app->singleton(\InFlow\Services\Loading\RelationResolutionService::class);
        $this->app->singleton(\InFlow\Services\Loading\RelationSyncService::class);
        $this->app->singleton(\InFlow\Services\Loading\PivotSyncService::class);
    }

    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/inflow.php' => config_path('inflow.php'),
        ], 'inflow-config');

        // Configure logging channel for InFlow
        $this->configureLogging();

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \InFlow\Commands\InFlowCommand::class,
                \InFlow\Commands\GenerateTestDataCommand::class,
            ]);
        }
    }

    /**
     * Configure dedicated logging channel for InFlow ETL operations
     *
     * This creates a separate log channel to isolate ETL logs from application logs,
     * making it easier to monitor and debug ETL operations.
     */
    private function configureLogging(): void
    {
        $logChannel = config('inflow.log_channel', 'inflow');
        $logPath = storage_path('logs/inflow.log');

        // Add inflow channel to logging config if not already present
        $channels = config('logging.channels', []);

        if (! isset($channels[$logChannel])) {
            config([
                'logging.channels.'.$logChannel => [
                    'driver' => 'daily',
                    'path' => $logPath,
                    'level' => env('INFLOW_LOG_LEVEL', 'info'),
                    'days' => env('INFLOW_LOG_DAYS', 14),
                    'replace_placeholders' => true,
                ],
            ]);
        }
    }
}
