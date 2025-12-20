<?php

namespace InFlow\Presenters\Contracts;

use InFlow\ViewModels\ColumnMappingInfoViewModel;
use InFlow\ViewModels\FlowRunViewModel;
use InFlow\ViewModels\FormatInfoViewModel;
use InFlow\ViewModels\MessageViewModel;
use InFlow\ViewModels\PreviewViewModel;
use InFlow\ViewModels\QualityReportViewModel;
use InFlow\ViewModels\SchemaViewModel;
use InFlow\ViewModels\SummaryViewModel;

/**
 * Interface for all presenters
 *
 * Defines the contract for rendering ViewModels to different output formats
 */
interface PresenterInterface
{
    public function presentFormatInfo(FormatInfoViewModel $viewModel): void;

    public function presentSchema(SchemaViewModel $viewModel): void;

    public function presentPreview(PreviewViewModel $viewModel): void;

    public function presentQualityReport(QualityReportViewModel $viewModel): void;

    public function presentFlowRun(FlowRunViewModel $viewModel): void;

    public function presentSummary(SummaryViewModel $viewModel): void;

    public function presentMessage(MessageViewModel $viewModel): void;

    public function presentColumnMappingInfo(ColumnMappingInfoViewModel $viewModel): void;
}
