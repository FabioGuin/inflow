<?php

namespace InFlow\Commands;

use Illuminate\Console\Command;
// Core Traits
use InFlow\Commands\Interactions\GuidedSetupInteraction;
use InFlow\Commands\Traits\Core\HandlesExceptions;
use InFlow\Commands\Traits\Core\HandlesFirstTimeSetup;
use InFlow\Commands\Traits\Core\HandlesOutput;
use InFlow\Commands\Traits\Core\HandlesProcessingLifecycle;
use InFlow\Commands\Traits\Core\HandlesUtility;
// Data Processing Traits
use InFlow\Commands\Traits\DataProcessing\HandlesSanitization;
use InFlow\Commands\Traits\File\HandlesFileSelection;
use InFlow\Services\Core\InFlowConsoleServices;
use InFlow\Services\Core\InFlowPipelineRunner;

class InFlowCommand extends Command
{
    use HandlesExceptions;
    use HandlesFileSelection;
    use HandlesFirstTimeSetup;
    use HandlesOutput;
    use HandlesProcessingLifecycle;
    use HandlesSanitization;
    use HandlesUtility;

    /**
     * Create a new command instance.
     */
    public function __construct(
        private readonly InFlowConsoleServices $services,
        private readonly InFlowPipelineRunner $pipelineRunner
    ) {
        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     * Note: --quiet option is available (Laravel built-in) for quiet mode - no prompts, no delays, minimal output
     *
     * @var string
     */
    protected $signature = 'inflow:process
                            {from? : Source file path (CSV/Excel) - will prompt if not provided}
                            {to? : Target model class (FQCN, e.g., App\\\\Models\\\\User) - will prompt if not provided}
                            {--sanitize= : Apply sanitization to the file (1/0, true/false, y/n - default: yes, will prompt if not specified)}
                            {--newline-format= : Newline format - options: lf, crlf, cr (default: lf)}
                            {--preview= : Number of rows to preview (default: 5)}
                            {--mapping= : Path to mapping definition file (JSON) - auto-detected if not provided}
                            {--error-report : Generate detailed error report file on failure}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process a file through InFlow ETL engine';

    /**
     * Configuration from guided setup
     *
     * @var array<string, mixed>
     */
    private array $guidedConfig = [];

    /**
     * Guided setup wizard delegate (used by HandlesFirstTimeSetup).
     *
     * @return array<string, mixed>
     */
    private function guidedSetup(): array
    {
        return (new GuidedSetupInteraction($this))->guidedSetup();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);

        try {
            $this->note('Starting InFlow processing...');

            $this->runFirstTimeSetupIfNeeded();

            $filePath = $this->requireFilePath();
            $context = $this->createProcessingContext($filePath, $startTime);

            $context = $this->pipelineRunner->run($this, $context);

            $this->displayProcessingSummary($context, $startTime);

            return $this->getExitCode($context);
        } catch (\RuntimeException $e) {
            return $this->handleRuntimeException($e);
        } catch (\Exception $e) {
            return $this->handleUnexpectedException($e);
        }
    }
}
