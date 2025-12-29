<?php

namespace InFlow\Commands;

use Illuminate\Console\Command;
use InFlow\Commands\Interactions\MappingInteraction;
use InFlow\Commands\Traits\Core\HandlesOutput;
use InFlow\Presenters\ConsolePresenter;
use InFlow\Services\File\ModelSelectionService;
use InFlow\Services\Loading\RelationTypeService;
use InFlow\Services\Mapping\MappingOrchestrator;
use InFlow\ValueObjects\Mapping\MappingContext;

/**
 * Command to create/configure mapping file.
 *
 * Delegates all logic to MappingOrchestrator, following DRY and KISS principles.
 */
class MakeMappingCommand extends Command
{
    use HandlesOutput;
    protected $signature = 'inflow:make-mapping
                            {file : Source file path (CSV/Excel/JSON)}
                            {model? : Target model class (FQCN) - will prompt if not provided}
                            {--output= : Path to save mapping file (default: mappings/{ModelClass}.json)}
                            {--force : Overwrite existing mapping file}
                            {--sanitize : Apply sanitization to the file before analysis}
                            {--sanitize-on-run : Include sanitizer config in flow_config for recurring processes}';

    protected $description = 'Create or configure mapping file for ETL import';

    public function __construct(
        private readonly MappingOrchestrator $orchestrator,
        private readonly ModelSelectionService $modelSelectionService,
        private readonly RelationTypeService $relationTypeService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $filePath = $this->argument('file');

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return Command::FAILURE;
        }

        $this->info("📄 Creating mapping for: {$filePath}");
        $this->newLine();

        // Create interaction handler and inject into orchestrator
        $interaction = new MappingInteraction($this, $this->modelSelectionService, $this->relationTypeService);
        $this->orchestrator->setInteraction($interaction);

        $context = new MappingContext($filePath);
        $presenter = new ConsolePresenter($this);
        $context = $this->orchestrator->process($this, $context, $presenter);

        if ($context->cancelled) {
            return Command::FAILURE;
        }

        if ($context->outputPath !== null) {
            $this->newLine();
            $this->info("✅ Mapping creation workflow completed");
            if ($context->sourceSchema !== null) {
                $this->line("   Schema analyzed: ".count($context->sourceSchema->columns)." columns, {$context->sourceSchema->totalRows} rows");
            }
        }

        return Command::SUCCESS;
    }
}

