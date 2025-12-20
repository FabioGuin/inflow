<?php

namespace InFlow\Services\Formatter;

use InFlow\ValueObjects\Data\QualityReport;
use InFlow\ViewModels\QualityReportViewModel;

/**
 * Formatter for quality report
 */
readonly class QualityReportFormatter
{
    public function format(QualityReport $qualityReport): QualityReportViewModel
    {
        return new QualityReportViewModel(
            title: 'Quality Report',
            errors: $qualityReport->errors,
            warnings: $qualityReport->warnings,
            hasIssues: $qualityReport->hasIssues(),
        );
    }
}
