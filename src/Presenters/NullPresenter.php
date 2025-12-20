<?php

namespace InFlow\Presenters;

use InFlow\Presenters\Contracts\PresenterInterface;
use InFlow\ViewModels\ColumnMappingInfoViewModel;
use InFlow\ViewModels\FlowRunViewModel;
use InFlow\ViewModels\FormatInfoViewModel;
use InFlow\ViewModels\MessageViewModel;
use InFlow\ViewModels\PreviewViewModel;
use InFlow\ViewModels\QualityReportViewModel;
use InFlow\ViewModels\SchemaViewModel;
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

    public function presentColumnMappingInfo(ColumnMappingInfoViewModel $viewModel): void
    {
        // No-op
    }
}
