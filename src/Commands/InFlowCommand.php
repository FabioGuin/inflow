<?php

namespace InFlow\Commands;

use Illuminate\Console\Command;
// Core Traits
use InFlow\Commands\Traits\Core\HandlesExceptions;
use InFlow\Commands\Traits\Core\HandlesOutput;
use InFlow\Commands\Traits\Core\HandlesProcessingLifecycle;
use InFlow\Commands\Traits\File\HandlesFileSelection;
use InFlow\Presenters\ConsolePresenter;
use InFlow\Services\Core\ConfigurationResolver;
use InFlow\Services\ETLOrchestrator;
use InFlow\Services\File\FileSelectionService;
use InFlow\Services\Formatter\FlowRunFormatter;
use InFlow\Services\Formatter\SummaryFormatter;
use InFlow\Services\Formatter\SummaryFormatterService;

class InFlowCommand extends Command
{
    use HandlesExceptions;
    use HandlesFileSelection;
    use HandlesOutput;
    use HandlesProcessingLifecycle;

    /**
     * Create a new command instance.
     */
    public function __construct(
        private readonly ConfigurationResolver $configResolver,
        private readonly FileSelectionService $fileSelectionService,
        private readonly SummaryFormatterService $summaryFormatter,
        private readonly ETLOrchestrator $orchestrator,
        private readonly FlowRunFormatter $flowRunFormatter,
        private readonly SummaryFormatter $summaryFormatterNew
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
                            {--sanitize= : Apply sanitization to the file (1/0, true/false, y/n - default: from config)}
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
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);

        try {
            $this->note('Starting InFlow processing...');

            $filePath = $this->requireFilePath();
            $context = $this->createProcessingContext($filePath, $startTime);

            $presenter = new ConsolePresenter($this);
            $context = $this->orchestrator->process($this, $context, $presenter);

            $this->displayProcessingSummary($context, $startTime, $presenter, $this->flowRunFormatter, $this->summaryFormatterNew);

            return $this->getExitCode($context);
        } catch (\RuntimeException $e) {
            return $this->handleRuntimeException($e);
        } catch (\Exception $e) {
            return $this->handleUnexpectedException($e);
        }
    }
}
