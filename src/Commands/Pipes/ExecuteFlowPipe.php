<?php

namespace InFlow\Commands\Pipes;

use Closure;
use InFlow\Commands\InFlowCommandContext;
use InFlow\Constants\DisplayConstants;
use InFlow\Contracts\ProcessingPipeInterface;
use InFlow\Enums\AnsiEscapeCode;
use InFlow\Enums\NewlineFormat;
use InFlow\Executors\FlowExecutor;
use InFlow\Loaders\EloquentLoader;
use InFlow\Mappings\MappingValidator;
use InFlow\Profilers\Profiler;
use InFlow\Services\Core\FlowBuilderService;
use InFlow\Services\Core\FlowEventService;
use InFlow\Services\Core\FlowExecutionService;
use InFlow\Services\Core\FlowRunBuilderService;
use InFlow\Services\Formatter\FlowRunResultsFormatterService;
use InFlow\Services\Formatter\FlowWarningFormatterService;
use InFlow\Services\Formatter\ProgressFormatterService;
use InFlow\Services\Reporting\ErrorClassifier;
use InFlow\Services\Reporting\ErrorReportGenerator;
use InFlow\ValueObjects\DetectedFormat;
use InFlow\ValueObjects\FlowRun;
use InFlow\ValueObjects\MappingDefinition;
use InFlow\ValueObjects\ProcessingContext;

use function Laravel\Prompts\progress;

/**
 * Eighth step of the ETL pipeline: execute the ETL flow.
 *
 * Validates mapping, builds and executes the flow, displays progress,
 * and shows execution results.
 */
class ExecuteFlowPipe implements ProcessingPipeInterface
{
    private $progressBar = null;

    private int $currentProgressStep = 0;

    /**
     * Per-run error handling mode for row-level errors.
     *
     * Values:
     * - null: ask on first error
     * - 'continue': keep going, ask again next time
     * - 'continue_silent': keep going and never ask again in this run
     * - 'stop': stop immediately
     * - 'stop_on_error': keep going but stop at the next error
     */
    private ?string $rowErrorHandlingMode = null;

    public function __construct(
        private readonly InFlowCommandContext $command,
        private readonly FlowBuilderService $flowBuilderService,
        private readonly FlowExecutionService $flowExecutionService,
        private readonly FlowRunBuilderService $flowRunBuilderService,
        private readonly FlowEventService $flowEventService,
        private readonly FlowWarningFormatterService $warningFormatter,
        private readonly Profiler $profiler,
        private readonly EloquentLoader $eloquentLoader,
        private readonly MappingValidator $mappingValidator,
        private readonly ProgressFormatterService $progressFormatter,
        private readonly FlowRunResultsFormatterService $flowRunResultsFormatter,
        private readonly ErrorClassifier $errorClassifier,
        private readonly ErrorReportGenerator $errorReportGenerator
    ) {}

    /**
     * Execute ETL flow and update processing context.
     *
     * Validates mapping, builds and executes the flow, displays progress,
     * and shows execution results.
     *
     * @param  ProcessingContext  $context  The processing context
     * @param  Closure  $next  The next pipe in the pipeline
     * @return ProcessingContext Updated context with flow run results
     */
    public function handle(ProcessingContext $context, Closure $next): ProcessingContext
    {
        if ($context->cancelled) {
            return $next($context);
        }

        if ($context->mappingDefinition === null || $context->reader === null || $context->format === null) {
            $this->command->warning('Flow execution skipped (no mapping available)');

            return $next($context);
        }

        $this->command->infoLine('<fg=blue>Step 8/9:</> <fg=gray>Executing ETL flow...</>');

        $this->command->note('Importing data into database. Rows are processed in chunks for optimal performance.', 'info');

        // Validate mapping before execution and get updated mapping if auto-mapped
        $validationResult = $this->command->validateMappingBeforeExecution($context->mappingDefinition, $context->sourceSchema);

        if ($validationResult === false) {
            // User chose not to proceed or fix issues
            $this->command->error('Import cancelled due to mapping validation issues.');

            // Return context with cancelled flag
            return $context->withCancelled();
        }

        // $validationResult can be the updated mapping or true (continue)
        if ($validationResult instanceof MappingDefinition) {
            $context = $context->withMappingDefinition($validationResult);
        }

        $shouldSanitize = $context->shouldSanitize ?? true;
        $flowRun = $this->executeFlow($context->filePath, $context->mappingDefinition, $context->format, $shouldSanitize);
        $context = $context->withFlowRun($flowRun);

        $this->finishFlowProgress($flowRun);

        if (! $this->command->isQuiet()) {
            $this->displayFlowRunResults($flowRun);
        }

        // Generate error report if requested and there are errors
        $this->generateErrorReportIfRequested($flowRun, $context);

        return $next($context);
    }

    /**
     * Execute Flow using FlowExecutor.
     *
     * Separates business logic (flow building) from presentation (progress updates).
     * Uses FlowBuilderService for business logic.
     *
     * @param  string  $filePath  The source file path
     * @param  MappingDefinition  $mapping  The mapping definition
     * @param  DetectedFormat  $format  The detected format
     * @param  bool  $sanitize  Whether to sanitize the content
     * @return FlowRun The flow execution results
     */
    private function executeFlow(string $filePath, MappingDefinition $mapping, DetectedFormat $format, bool $sanitize): FlowRun
    {
        // Business logic: build sanitizer config
        $sanitizerConfig = $this->command->getSanitizerConfig();

        // Business logic: build Flow using service
        $flow = $this->flowBuilderService->buildFlow(
            $filePath,
            $mapping,
            $format,
            $sanitize,
            $sanitizerConfig
        );

        // Presentation: create executor with progress callback
        $executor = new FlowExecutor(
            $this->flowExecutionService,
            $this->flowRunBuilderService,
            $this->flowEventService,
            $this->warningFormatter,
            $this->profiler,
            $this->eloquentLoader,
            $this->mappingValidator,
            function (FlowRun $run) {
                $this->updateFlowProgress($run);
            },
            function (\Throwable $e, \InFlow\ValueObjects\Row $row, \InFlow\ValueObjects\Flow $flow, int $rowNumber): string {
                return $this->decideOnRowError($e, $rowNumber);
            }
        );

        // Business logic: execute flow
        return $executor->execute($flow, $filePath);
    }

    private function decideOnRowError(\Throwable $e, int $rowNumber): string
    {
        if ($this->command->isQuiet() || $this->command->option('no-interaction')) {
            return 'continue';
        }

        if ($this->rowErrorHandlingMode === 'continue_silent') {
            return 'continue';
        }

        if ($this->rowErrorHandlingMode === 'stop_on_error') {
            return 'stop_on_error';
        }

        if ($this->rowErrorHandlingMode === 'stop') {
            return 'stop';
        }

        [$title, $hint] = $this->classifyRowError($e);

        $this->command->newLine();
        $this->command->warning("Row {$rowNumber}: {$title}");

        if ($hint !== null) {
            $this->command->line("  <fg=gray>{$hint}</>");
        }

        $choice = $this->command->choice(
            'How do you want to proceed?',
            [
                'continue' => 'Continue (skip this row and collect errors)',
                'continue_silent' => 'Continue and don\'t ask again (this run)',
                'stop_on_error' => 'Continue but stop on the next error',
                'stop' => 'Stop now (fail the run)',
            ],
            'continue'
        );

        $this->rowErrorHandlingMode = is_string($choice) ? $choice : 'continue';

        return $this->rowErrorHandlingMode;
    }

    /**
     * Classify a row-level error using the centralized ErrorClassifier.
     *
     * @return array{0: string, 1: string|null}
     */
    private function classifyRowError(\Throwable $e): array
    {
        $classification = $this->errorClassifier->classify($e);

        return [$classification['type'], $classification['hint']];
    }

    /**
     * Update progress display during flow execution.
     *
     * Separates business logic (formatting progress data) from presentation
     * (progress bar, output). Uses ProgressFormatterService for business logic.
     *
     * @param  FlowRun  $run  The current flow run state
     */
    private function updateFlowProgress(FlowRun $run): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        // Presentation: initialize progress bar on first call when totalRows is available
        if ($this->progressBar === null && $run->totalRows > 0) {
            $this->progressBar = progress(
                label: 'Importing data into database',
                steps: $run->totalRows
            );
            $this->currentProgressStep = 0;
        }

        // Update progress bar with current counters
        if ($this->progressBar !== null && $run->totalRows > 0) {
            // Business logic: calculate current step and steps to advance
            $currentStep = $this->progressFormatter->calculateCurrentStep($run);
            $stepsToAdvance = $this->progressFormatter->calculateStepsToAdvance($run, $this->currentProgressStep);

            // Business logic: build progress label with hint
            $progressLabel = $this->progressFormatter->buildProgressLabel($run, 'Importing data |');

            // Presentation: update label if method exists
            if (method_exists($this->progressBar, 'label')) {
                $this->progressBar->label($progressLabel);
            }

            // Presentation: advance progress bar
            if ($stepsToAdvance > 0) {
                $this->progressBar->advance($stepsToAdvance);
                $this->currentProgressStep = $currentStep;
            }

            // Business logic: build counter text
            $counterText = $this->progressFormatter->buildCounterText($run);

            // Presentation: write counters below progress bar using ANSI escape codes
            $this->command->getOutput()->write(AnsiEscapeCode::MoveUp->value.AnsiEscapeCode::ClearLine->value.$counterText."\n");

            // Presentation: force output flush for real-time display
            $this->command->flushOutput();
        } else {
            // Business logic: build fallback progress text
            $fallbackText = $this->progressFormatter->buildFallbackProgressText($run);

            // Presentation: use line with carriage return for real-time update
            $this->command->getOutput()->write(NewlineFormat::Cr->getCharacter().$fallbackText);
        }
    }

    /**
     * Finish progress bar display.
     *
     * Separates business logic (calculating remaining steps) from presentation
     * (finishing progress bar). Uses ProgressFormatterService for business logic.
     *
     * @param  FlowRun  $run  The flow run results
     */
    private function finishFlowProgress(FlowRun $run): void
    {
        if ($this->progressBar !== null) {
            // Business logic: calculate remaining steps to reach 100%
            $remainingSteps = $run->totalRows - $this->currentProgressStep;

            // Presentation: ensure we're at 100%
            if ($remainingSteps > 0) {
                $this->progressBar->advance($remainingSteps);
                $this->currentProgressStep = $run->totalRows;
            }

            // Presentation: finish progress bar
            $this->progressBar->finish();
            $this->progressBar = null;
            $this->currentProgressStep = 0;
            $this->command->newLine();
        }
    }

    /**
     * Display FlowRun results.
     *
     * Separates business logic (formatting results data) from presentation
     * (output, lines). Uses FlowRunResultsFormatterService for business logic.
     *
     * @param  FlowRun  $run  The flow run results
     */
    private function displayFlowRunResults(FlowRun $run): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        // Presentation: display header
        $this->command->newLine();
        $this->command->line('<fg=cyan>Flow Execution Results</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        // Business logic: format status
        $this->command->line($this->flowRunResultsFormatter->formatStatusLine($run));

        // Business logic: format statistics
        foreach ($this->flowRunResultsFormatter->formatStatisticsLines($run) as $line) {
            $this->command->line($line);
        }

        // Business logic: format duration
        $durationLine = $this->flowRunResultsFormatter->formatDurationLine($run);
        if ($durationLine !== null) {
            $this->command->line($durationLine);
        }

        // Business logic: format errors
        if ($this->flowRunResultsFormatter->shouldDisplayErrors($run)) {
            $this->command->newLine();
            $this->command->line('<fg=red>Errors encountered:</>');
            foreach ($this->flowRunResultsFormatter->formatErrorLines($run) as $line) {
                $this->command->line($line);
            }
        }

        // Business logic: format warnings
        if ($this->flowRunResultsFormatter->shouldDisplayWarnings($run)) {
            $this->command->newLine();
            $this->command->line('<fg=yellow>Warnings:</>');
            foreach ($this->flowRunResultsFormatter->formatWarningLines($run) as $line) {
                $this->command->line($line);
            }
        }

        $this->command->newLine();
    }

    /**
     * Generate detailed error report file if --error-report option is set and there are errors or skipped rows.
     */
    private function generateErrorReportIfRequested(FlowRun $flowRun, ProcessingContext $context): void
    {
        if (! $this->command->option('error-report')) {
            return;
        }

        // Generate report if there are errors OR skipped rows (validation failures)
        if ($flowRun->errorCount === 0 && $flowRun->skippedRows === 0) {
            return;
        }

        $reportPath = $this->errorReportGenerator->generate($flowRun, $context);

        if ($reportPath !== null) {
            $this->command->newLine();
            $this->command->line('<fg=cyan>📄 Error report saved:</> <fg=white>'.$reportPath.'</>');
        }
    }
}
