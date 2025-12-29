<?php

namespace InFlow\Presenters;

use InFlow\Commands\InFlowCommand;
use InFlow\Constants\DisplayConstants;
use InFlow\Enums\UI\MessageType;
use InFlow\Presenters\Contracts\PresenterInterface;
// TODO: Re-implement with new mapping system
use InFlow\ViewModels\FileInfoViewModel;
use InFlow\ViewModels\FlowRunViewModel;
use InFlow\ViewModels\FormatInfoViewModel;
use InFlow\ViewModels\MessageViewModel;
use InFlow\ViewModels\PreviewViewModel;
use InFlow\ViewModels\ProgressInfoViewModel;
use InFlow\ViewModels\QualityReportViewModel;
use InFlow\ViewModels\SchemaViewModel;
use InFlow\ViewModels\StepProgressViewModel;
use InFlow\ViewModels\StepSummaryViewModel;
use InFlow\ViewModels\SummaryViewModel;

/**
 * Console presenter implementation
 *
 * Renders ViewModels to console output using InFlowCommand
 */
readonly class ConsolePresenter implements PresenterInterface
{
    public function __construct(
        private InFlowCommand $command
    ) {}

    public function presentFormatInfo(FormatInfoViewModel $viewModel): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=cyan>'.$viewModel->title.'</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        $rows = [
            ['Type', $viewModel->type],
        ];

        if ($viewModel->delimiter !== null) {
            $rows[] = ['Delimiter', '"'.$viewModel->delimiter.'"'];
        }

        if ($viewModel->encoding !== null) {
            $rows[] = ['Encoding', $viewModel->encoding];
        }

        $rows[] = ['Has Header', $viewModel->hasHeader ? 'Yes' : 'No'];

        $this->command->table(['Property', 'Value'], $rows);
        $this->command->newLine();
    }

    public function presentSchema(SchemaViewModel $viewModel): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=cyan>'.$viewModel->title.'</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        $headers = ['Column', 'Type', 'Null %', 'Examples'];
        $tableData = [];

        foreach ($viewModel->columns as $column) {
            $examples = '';
            if (! empty($column['examples'])) {
                $examples = implode(', ', $column['examples']);
                if (count($column['examples']) > 3) {
                    $examples .= '...';
                }
            }

            $tableData[] = [
                $column['name'],
                $column['type'],
                $column['nullPercent'].'%',
                $examples ?: '<fg=gray>none</>',
            ];
        }

        $this->command->table($headers, $tableData);
    }

    public function presentPreview(PreviewViewModel $viewModel): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=cyan>'.$viewModel->title.'</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        if ($viewModel->headers !== null && ! empty($viewModel->headers)) {
            $tableData = [];
            foreach ($viewModel->tableData as $row) {
                $tableData[] = array_map(fn ($col) => $col ?? '<fg=gray>null</>', $row);
            }
            $this->command->table($viewModel->headers, $tableData);
        } elseif ($viewModel->rawRows !== null) {
            foreach ($viewModel->rawRows as $idx => $row) {
                $this->command->line('  <fg=gray>Row '.($idx + 1).':</> '.json_encode($row));
            }
        }

        $this->command->newLine();
    }

    public function presentQualityReport(QualityReportViewModel $viewModel): void
    {
        if ($this->command->isQuiet() || ! $viewModel->hasIssues) {
            if (! $this->command->isQuiet() && ! $viewModel->hasIssues) {
                $this->command->newLine();
                $this->command->success('Quality Report: No issues detected');
            }

            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=cyan>'.$viewModel->title.'</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        if (! empty($viewModel->errors)) {
            $this->command->newLine();
            $this->command->line('<fg=red>Errors:</>');
            foreach ($viewModel->errors as $error) {
                $this->command->line("  <fg=red>•</> {$error}");
            }
        }

        if (! empty($viewModel->warnings)) {
            $this->command->newLine();
            $this->command->line('<fg=yellow>Warnings:</>');
            foreach ($viewModel->warnings as $warning) {
                $this->command->line("  <fg=yellow>•</> {$warning}");
            }
        }

        $this->command->newLine();
    }

    public function presentFlowRun(FlowRunViewModel $viewModel): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=cyan>'.$viewModel->title.'</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        $statusIconColor = match ($viewModel->status) {
            'completed' => 'green',
            'partially_completed' => 'yellow',
            'failed' => 'red',
            default => 'white',
        };

        $this->command->line("<fg={$statusIconColor}>{$viewModel->statusIcon}</> Status: <fg=white>{$viewModel->status}</>");
        $this->command->line('  Imported: <fg=yellow>'.number_format($viewModel->importedRows).'</>');
        $this->command->line('  Skipped: <fg=yellow>'.number_format($viewModel->skippedRows).'</>');
        $this->command->line('  Errors: <fg=yellow>'.number_format($viewModel->errorCount).'</>');

        if ($viewModel->duration !== null) {
            $this->command->line('  Duration: <fg=yellow>'.round($viewModel->duration, 2).'s</>');
        }

        if (! empty($viewModel->errors)) {
            $this->command->newLine();
            $this->command->line('<fg=red>Error Details:</>');
            foreach ($viewModel->errors as $index => $error) {
                $errorNum = $index + 1;
                $this->command->line("  <fg=red>Error #{$errorNum}:</>");

                if (isset($error['message'])) {
                    $this->command->line('    <fg=yellow>Message:</> '.$error['message']);
                }

                if (isset($error['exception'])) {
                    $this->command->line('    <fg=yellow>Exception:</> '.$error['exception']);
                }

                if (isset($error['row']) || isset($error['rowNumber'])) {
                    $rowNum = $error['row'] ?? $error['rowNumber'] ?? 'unknown';
                    $this->command->line('    <fg=yellow>Row:</> '.$rowNum);
                }

                // Check for validation errors in context or directly in error
                $validationErrors = $error['errors'] ?? $error['context']['errors'] ?? $error['context']['validation_errors'] ?? null;

                if ($validationErrors !== null && is_array($validationErrors)) {
                    $this->command->line('    <fg=yellow>Validation Errors:</>');
                    foreach ($validationErrors as $field => $messages) {
                        if (is_array($messages)) {
                            foreach ($messages as $message) {
                                $this->command->line("      • <fg=gray>{$field}:</> {$message}");
                            }
                        } else {
                            $this->command->line("      • <fg=gray>{$field}:</> {$messages}");
                        }
                    }
                }
            }
        }

        $this->command->newLine();
    }

    public function presentSummary(SummaryViewModel $viewModel): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->line('<fg=cyan>'.$viewModel->title.'</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        $formattedTableData = [];
        foreach ($viewModel->tableData as $row) {
            $formattedTableData[] = [
                $row[0],
                "<fg=yellow>{$row[1]}</>",
            ];
        }

        $this->command->table($viewModel->headers, $formattedTableData);
        $this->command->newLine();

        $this->command->line('<fg=green>'.$viewModel->completionMessage.'</>');
        $this->command->newLine();
    }

    public function presentMessage(MessageViewModel $viewModel): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        match ($viewModel->type) {
            MessageType::Success => $this->command->success($viewModel->message),
            MessageType::Error => $this->command->error($viewModel->message),
            MessageType::Warning => $this->command->warning($viewModel->message),
            MessageType::Info => $this->command->note($viewModel->message),
        };
    }

    // TODO: Re-implement with new mapping system
    public function presentColumnMappingInfo(mixed $viewModel): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->newLine();

        $relationPrefix = $viewModel->isRelation ? '<fg=magenta>[Relation]</> ' : '';
        $confidenceColor = $viewModel->confidence >= DisplayConstants::CONFIDENCE_THRESHOLD_HIGH ? 'green' :
                          ($viewModel->confidence >= DisplayConstants::CONFIDENCE_THRESHOLD_MEDIUM ? 'yellow' : 'red');

        $this->command->line('  <fg=cyan>Column:</> <fg=yellow>'.$viewModel->sourceColumn.'</>');
        $this->command->line('  <fg=cyan>Suggested:</> '.$relationPrefix.'<fg=white>'.$viewModel->suggestedPath.'</> <fg='.$confidenceColor.'>(confidence: '.number_format($viewModel->confidence * 100, 0).'%)</>');

        $altParts = [];
        if (! empty($viewModel->fieldAlternatives)) {
            $altParts[] = '<fg=gray>Fields:</> '.implode(', ', $viewModel->fieldAlternatives);
        }
        if (! empty($viewModel->relationAlternatives)) {
            $altParts[] = '<fg=magenta>Relations:</> '.implode(', ', $viewModel->relationAlternatives);
        }

        if (! empty($altParts)) {
            $this->command->line('  <fg=cyan>Alternatives:</> '.implode(' | ', $altParts));
        }
    }

    public function presentStepProgress(StepProgressViewModel $viewModel): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->infoLine(
            '<fg=blue>Step '.$viewModel->currentStep.'/'.$viewModel->totalSteps.':</> '.
            '<fg=gray>'.$viewModel->stepDescription.'</>'
        );
    }

    public function presentFileInfo(FileInfoViewModel $viewModel): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->newLine();
        $this->command->infoLine('File Information');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        $this->command->table(
            ['Property', 'Value'],
            [
                ['Name', $viewModel->name],
                ['Extension', $viewModel->extension ?: '<fg=gray>none</>'],
                ['Size', $viewModel->size],
                ['MIME Type', $viewModel->mimeType ?: '<fg=gray>unknown</>'],
            ]
        );
        $this->command->newLine();
    }

    public function presentStepSummary(StepSummaryViewModel $viewModel): bool
    {
        if ($this->command->isQuiet() || $this->command->option('no-interaction')) {
            return true;
        }

        if (! $viewModel->showContinuePrompt) {
            return true;
        }

        $this->command->newLine();
        $this->command->line('<fg=green>✓</> <fg=white;options=bold>'.$viewModel->stepName.' completed</>');

        if (! empty($viewModel->summary)) {
            foreach ($viewModel->summary as $label => $value) {
                $this->command->line('  <fg=gray>'.$label.':</> <fg=white>'.$value.'</>');
            }
        }

        $this->command->newLine();

        $choice = \Laravel\Prompts\select(
            label: '  Continue?',
            options: [
                'continue' => '▶ Continue to next step',
                'cancel' => '✕ Cancel import',
            ],
            default: 'continue'
        );

        return $choice === 'continue';
    }

    public function presentProgressInfo(ProgressInfoViewModel $viewModel): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $parts = [];
        if ($viewModel->lines !== null) {
            $parts[] = '<fg=yellow>'.number_format($viewModel->lines).'</> lines';
        }
        if ($viewModel->bytes !== null) {
            $parts[] = '<fg=yellow>'.number_format($viewModel->bytes).'</> bytes';
        }
        if ($viewModel->rows !== null) {
            $parts[] = '<fg=yellow>'.number_format($viewModel->rows).'</> row(s)';
        }
        if ($viewModel->columns !== null) {
            $parts[] = '<fg=yellow>'.number_format($viewModel->columns).'</> column(s)';
        }

        $info = '<fg=green>✓</> '.$viewModel->message;
        if (! empty($parts)) {
            $info .= ': '.implode(', ', $parts);
        }

        $this->command->infoLine($info);
    }
}
