<?php

namespace InFlow\Commands\Pipes;

use Closure;
use InFlow\Commands\InFlowCommandContext;
use InFlow\Constants\DisplayConstants;
use InFlow\Contracts\ProcessingPipeInterface;
use InFlow\Contracts\ReaderInterface;
use InFlow\Profilers\Profiler;
use InFlow\Services\Formatter\QualityReportFormatterService;
use InFlow\Services\Formatter\SchemaFormatterService;
use InFlow\ValueObjects\ProcessingContext;
use InFlow\ValueObjects\QualityReport;
use InFlow\ValueObjects\SourceSchema;

/**
 * Sixth step of the ETL pipeline: profile data quality and structure.
 *
 * Analyzes the data structure, types, and quality issues to help identify
 * problems before import. Displays schema information and quality reports.
 */
readonly class ProfileDataPipe implements ProcessingPipeInterface
{
    public function __construct(
        private InFlowCommandContext $command,
        private Profiler $profiler,
        private SchemaFormatterService $schemaFormatter,
        private QualityReportFormatterService $qualityReportFormatter
    ) {}

    /**
     * Profile data and update processing context.
     *
     * Analyzes data structure, types, and quality issues, then displays
     * schema information and quality reports to the user.
     *
     * @param  ProcessingContext  $context  The processing context containing the reader
     * @param  Closure  $next  The next pipe in the pipeline
     * @return ProcessingContext Updated context with source schema
     */
    public function handle(ProcessingContext $context, Closure $next): ProcessingContext
    {
        if ($context->cancelled) {
            return $next($context);
        }

        if ($context->reader === null) {
            $this->command->warning('Profiling skipped (no data reader available)');

            $this->command->newLine();

            return $next($context);
        }

        $this->command->infoLine('<fg=blue>Step 6/9:</> <fg=gray>Profiling data quality...</>');

        $this->command->note('Analyzing data structure, types, and quality issues. This helps identify problems before import.', 'info');


        // Rewind reader to start from beginning for profiling
        $context->reader->rewind();
        $result = $this->profileData($context->reader);
        $context = $context->withSourceSchema($result['schema']);


        // Checkpoint after profiling
        $schema = $result['schema'];
        $qualityReport = $result['quality_report'];

        $checkpointResult = $this->command->checkpoint('Data profiling', [
            'Rows analyzed' => number_format($schema->totalRows),
            'Columns detected' => (string) count($schema->columns),
            'Quality issues' => $qualityReport->hasIssues() ? 'Yes (see above)' : 'None',
        ]);

        if ($checkpointResult === 'cancel') {
            return $next($context->withCancelled());
        }

        return $next($context);
    }

    /**
     * Profile data using Profiler and display results.
     *
     * @param  ReaderInterface  $reader  The reader to profile
     * @return array{schema: SourceSchema, quality_report: QualityReport}
     */
    private function profileData(ReaderInterface $reader): array
    {
        $result = $this->profiler->profile($reader);

        $schema = $result['schema'];
        $qualityReport = $result['quality_report'];

        $this->command->success('Profiling completed');
        $this->command->infoLine('  <fg=gray>→</> Analyzed <fg=yellow>'.number_format($schema->totalRows).'</> row(s)');
        $this->command->infoLine('  <fg=gray>→</> Detected <fg=yellow>'.count($schema->columns).'</> column(s)');

        if ($this->command->isQuiet()) {
            // Quiet mode: only show errors
            if ($qualityReport->hasErrors()) {
                foreach ($qualityReport->errors as $error) {
                    $this->command->error("  Error: {$error}");
                }
            }
        } else {
            // Normal mode: always show detailed information
            $this->displaySchema($schema);
            $this->displayQualityReport($qualityReport);
        }

        return $result;
    }

    /**
     * Display schema information.
     *
     * @param  SourceSchema  $schema  The source schema
     */
    private function displaySchema(SourceSchema $schema): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=cyan>Data Schema</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        // Business logic: format schema data
        $formatted = $this->schemaFormatter->formatForTable($schema);

        // Presentation: display table
        $this->command->table($formatted['headers'], $formatted['table_data']);

        // Presentation: display examples if very verbose
        if ($this->command->getOutput()->isVeryVerbose()) {
            $this->displaySchemaExamples($schema);
        }
    }

    /**
     * Display schema examples (very verbose mode).
     *
     * @param  SourceSchema  $schema  The source schema
     */
    private function displaySchemaExamples(SourceSchema $schema): void
    {
        $this->command->newLine();
        $this->command->line('<fg=cyan>Examples:</>');

        foreach ($schema->columns as $column) {
            $examples = $this->schemaFormatter->formatExamples($column);
            if ($examples !== null) {
                $this->command->line("  <fg=gray>{$column->name}:</> {$examples}");
            }
        }
    }

    /**
     * Display quality report.
     *
     * @param  QualityReport  $qualityReport  The quality report
     */
    private function displayQualityReport(QualityReport $qualityReport): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        if (! $qualityReport->hasIssues()) {
            $this->command->newLine();
            $this->command->success('Quality Report: No issues detected');

            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=cyan>Quality Report</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        // Business logic: format report data
        $formatted = $this->qualityReportFormatter->formatForDisplay($qualityReport);

        // Presentation: display errors
        if (! empty($formatted['errors'])) {
            $this->displayQualityReportErrors($formatted['errors']);
        }

        // Presentation: display warnings
        if (! empty($formatted['warnings'])) {
            $this->displayQualityReportWarnings($formatted['warnings']);
        }

        // Presentation: display anomalies
        if (! empty($formatted['anomalies'])) {
            $this->displayQualityReportAnomalies($formatted['anomalies']);
        }

        $this->command->newLine();
    }

    /**
     * Display quality report errors.
     *
     * @param  array<int, array{message: string}>  $errors  Formatted errors
     */
    private function displayQualityReportErrors(array $errors): void
    {
        $this->command->newLine();
        $this->command->line('<fg=red>Errors:</>');
        foreach ($errors as $error) {
            $this->command->line("  <fg=red>•</> {$error['message']}");
        }
    }

    /**
     * Display quality report warnings.
     *
     * @param  array<int, array{message: string}>  $warnings  Formatted warnings
     */
    private function displayQualityReportWarnings(array $warnings): void
    {
        $this->command->newLine();
        $this->command->line('<fg=yellow>Warnings:</>');
        foreach ($warnings as $warning) {
            $this->command->line("  <fg=yellow>•</> {$warning['message']}");
        }
    }

    /**
     * Display quality report anomalies.
     *
     * @param  array<int, array{column: string, type: string, count: int, details: array<int, array{value: string, count: int|null}>}>  $anomalies  Formatted anomalies
     */
    private function displayQualityReportAnomalies(array $anomalies): void
    {
        $this->command->newLine();
        $this->command->line('<fg=cyan>🔍 Anomalies:</>');

        foreach ($anomalies as $anomaly) {
            $this->command->line("  <fg=cyan>Column '{$anomaly['column']}':</>");

            if ($anomaly['type'] === 'duplicates') {
                $this->displayDuplicateAnomaly($anomaly);
            } elseif ($anomaly['type'] === 'invalid_dates') {
                $this->displayInvalidDateAnomaly($anomaly);
            }
        }
    }

    /**
     * Display duplicate anomaly details.
     *
     * @param  array{column: string, type: string, count: int, details: array<int, array{value: string, count: int|null}>}  $anomaly  The duplicate anomaly
     */
    private function displayDuplicateAnomaly(array $anomaly): void
    {
        $message = sprintf('%d duplicate value(s) found', $anomaly['count']);
        $this->command->line("    <fg=yellow>→</> {$message}");

        if ($this->command->getOutput()->isVeryVerbose()) {
            $examples = $this->qualityReportFormatter->getLimitedExamples($anomaly['details']);
            foreach ($examples as $detail) {
                $detailMessage = sprintf("'%s' appears %d time(s)", $detail['value'], $detail['count']);
                $this->command->line("      <fg=gray>•</> {$detailMessage}");
            }
        }
    }

    /**
     * Display invalid date anomaly details.
     *
     * @param  array{column: string, type: string, count: int, details: array<int, array{value: string, count: int|null}>}  $anomaly  The invalid date anomaly
     */
    private function displayInvalidDateAnomaly(array $anomaly): void
    {
        $message = sprintf('%d invalid date(s) found', $anomaly['count']);
        $this->command->line("    <fg=red>→</> {$message}");

        if ($this->command->getOutput()->isVeryVerbose()) {
            $examples = $this->qualityReportFormatter->getLimitedExamples($anomaly['details']);
            foreach ($examples as $detail) {
                $this->command->line("      <fg=gray>•</> '{$detail['value']}'");
            }
        }
    }
}
