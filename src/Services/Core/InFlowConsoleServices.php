<?php

namespace InFlow\Services\Core;

use InFlow\Detectors\FormatDetector;
use InFlow\Loaders\EloquentLoader;
use InFlow\Mappings\MappingBuilder;
use InFlow\Mappings\MappingSerializer;
use InFlow\Profilers\Profiler;
use InFlow\Services\DataProcessing\ContentUtilityService;
use InFlow\Services\DataProcessing\DataPreviewService;
use InFlow\Services\DataProcessing\SanitizationService;
use InFlow\Services\File\FileReaderService;
use InFlow\Services\File\FileSelectionService;
use InFlow\Services\File\FileWriterService;
use InFlow\Services\File\ModelSelectionService;
use InFlow\Services\Formatter\DataPreviewFormatterService;
use InFlow\Services\Formatter\FlowRunResultsFormatterService;
use InFlow\Services\Formatter\FlowWarningFormatterService;
use InFlow\Services\Formatter\FormatFormatterService;
use InFlow\Services\Formatter\MappingFormatterService;
use InFlow\Services\Formatter\ProgressFormatterService;
use InFlow\Services\Formatter\QualityReportFormatterService;
use InFlow\Services\Formatter\ReportFormatterService;
use InFlow\Services\Formatter\SchemaFormatterService;
use InFlow\Services\Formatter\SummaryFormatterService;
use InFlow\Services\Loading\RelationTypeService;
use InFlow\Services\Mapping\MappingGenerationService;
use InFlow\Services\Mapping\MappingHistoryService;
use InFlow\Services\Mapping\MappingProcessingService;
use InFlow\Services\Mapping\MappingValidationService;
use InFlow\Services\Mapping\TransformFormatterService;
use InFlow\Services\Mapping\TransformSelectionService;

readonly class InFlowConsoleServices
{
    public function __construct(
        public ConfigurationResolver $configResolver,
        public FlowBuilderService $flowBuilderService,
        public FlowExecutionService $flowExecutionService,
        public FlowRunBuilderService $flowRunBuilderService,
        public FlowEventService $flowEventService,
        public FileReaderService $fileReader,
        public FileWriterService $fileWriter,
        public FileSelectionService $fileSelectionService,
        public ModelSelectionService $modelSelectionService,
        public ReportFormatterService $reportFormatter,
        public FormatFormatterService $formatFormatter,
        public DataPreviewFormatterService $dataPreviewFormatter,
        public SchemaFormatterService $schemaFormatter,
        public QualityReportFormatterService $qualityReportFormatter,
        public SummaryFormatterService $summaryFormatter,
        public ProgressFormatterService $progressFormatter,
        public FlowRunResultsFormatterService $flowRunResultsFormatter,
        public MappingFormatterService $mappingFormatter,
        public FlowWarningFormatterService $warningFormatter,
        public SanitizationService $sanitizationService,
        public ContentUtilityService $contentUtility,
        public FormatDetector $formatDetector,
        public Profiler $profiler,
        public DataPreviewService $dataPreviewService,
        public MappingSerializer $mappingSerializer,
        public MappingBuilder $mappingBuilder,
        public MappingProcessingService $mappingProcessingService,
        public MappingGenerationService $mappingGenerationService,
        public MappingHistoryService $mappingHistoryService,
        public TransformSelectionService $transformSelectionService,
        public TransformFormatterService $transformFormatterService,
        public MappingValidationService $mappingValidationService,
        public EloquentLoader $eloquentLoader,
        public RelationTypeService $relationTypeService
    ) {}
}
