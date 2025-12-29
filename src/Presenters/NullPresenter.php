<?php

namespace InFlow\Presenters;

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
 * Null presenter implementation
 *
 * Does nothing - used when presenter is not available or for quiet mode
 */
readonly class NullPresenter implements PresenterInterface
{
    public function presentFormatInfo(FormatInfoViewModel $viewModel): void
    {
        // No-op
    }

    public function presentSchema(SchemaViewModel $viewModel): void
    {
        // No-op
    }

    public function presentPreview(PreviewViewModel $viewModel): void
    {
        // No-op
    }

    public function presentQualityReport(QualityReportViewModel $viewModel): void
    {
        // No-op
    }

    public function presentFlowRun(FlowRunViewModel $viewModel): void
    {
        // No-op
    }

    public function presentSummary(SummaryViewModel $viewModel): void
    {
        // No-op
    }

    public function presentMessage(MessageViewModel $viewModel): void
    {
        // No-op
    }

    // TODO: Re-implement with new mapping system
    public function presentColumnMappingInfo(mixed $viewModel): void
    {
        // No-op
    }

    public function presentStepProgress(StepProgressViewModel $viewModel): void
    {
        // No-op
    }

    public function presentFileInfo(FileInfoViewModel $viewModel): void
    {
        // No-op
    }

    public function presentStepSummary(StepSummaryViewModel $viewModel): bool
    {
        // Always continue in null presenter
        return true;
    }

    public function presentProgressInfo(ProgressInfoViewModel $viewModel): void
    {
        // No-op
    }
}
