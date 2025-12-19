<?php

namespace InFlow\Commands\Pipes;

use Closure;
use InFlow\Commands\InFlowCommandContext;
use InFlow\Constants\DisplayConstants;
use InFlow\Contracts\ProcessingPipeInterface;
use InFlow\Contracts\ReaderInterface;
use InFlow\Enums\ConfigKey;
use InFlow\Readers\CsvReader;
use InFlow\Readers\ExcelReader;
use InFlow\Readers\JsonLinesReader;
use InFlow\Readers\XmlReader;
use InFlow\Services\Core\ConfigurationResolver;
use InFlow\Services\DataProcessing\DataPreviewService;
use InFlow\Services\Formatter\DataPreviewFormatterService;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\DetectedFormat;
use InFlow\ValueObjects\ProcessingContext;

use function Laravel\Prompts\table;

/**
 * Fifth step of the ETL pipeline: read structured data from file.
 *
 * Creates appropriate reader (CSV, Excel, or JSON Lines) based on detected format,
 * reads preview rows, and displays them to the user.
 */
readonly class ReadDataPipe implements ProcessingPipeInterface
{
    public function __construct(
        private InFlowCommandContext $command,
        private ConfigurationResolver $configResolver,
        private DataPreviewService $dataPreviewService,
        private DataPreviewFormatterService $dataPreviewFormatter
    ) {}

    /**
     * Read structured data and update processing context.
     *
     * Creates appropriate reader based on format type, reads preview rows,
     * and displays them to the user.
     *
     * @param  ProcessingContext  $context  The processing context containing source and format
     * @param  Closure  $next  The next pipe in the pipeline
     * @return ProcessingContext Updated context with reader instance
     */
    public function handle(ProcessingContext $context, Closure $next): ProcessingContext
    {
        if ($context->cancelled) {
            return $next($context);
        }

        if ($context->source === null || $context->format === null) {
            return $next($context);
        }

        $reader = null;

        if ($context->format->type->isCsv()) {
            $this->command->infoLine('<fg=blue>Step 5/9:</> <fg=gray>Reading CSV data...</>');
            $reader = $this->readCsvData($context->source, $context->format);
        } elseif ($context->format->type->isExcel()) {
            $this->command->infoLine('<fg=blue>Step 5/9:</> <fg=gray>Reading Excel data...</>');
            $reader = $this->readExcelData($context->source, $context->format);
        } elseif ($context->format->type->isJson()) {
            $this->command->infoLine('<fg=blue>Step 5/9:</> <fg=gray>Reading JSON Lines data...</>');
            $reader = $this->readJsonData($context->source, $context->format);
        } elseif ($context->format->type->isXml()) {
            $this->command->infoLine('<fg=blue>Step 5/9:</> <fg=gray>Reading XML data...</>');
            $reader = $this->readXmlData($context->source, $context->format);
        } else {
            $this->command->warning('Data reading skipped (unsupported file type: '.$context->format->type->value.')');
        }

        if ($reader !== null) {
            $context = $context->withReader($reader);
        }

        $this->command->newLine();

        // Checkpoint: Allow user to review data preview
        if (! $this->command->checkpoint('Data read completed')) {
            $context->cancelled = true;

            return $context;
        }

        return $next($context);
    }

    /**
     * Read CSV data using CsvReader and display preview.
     *
     * @param  FileSource  $source  The file source
     * @param  DetectedFormat  $format  The detected format
     * @return CsvReader The CSV reader instance
     */
    private function readCsvData(FileSource $source, DetectedFormat $format): CsvReader
    {
        /** @var CsvReader */
        return $this->readDataWithPreview(
            new CsvReader($source, $format),
            fn (ReaderInterface $reader, int $rowCount) => "<fg=green>✓ Read</> <fg=yellow>{$rowCount}</> row(s)"
        );
    }

    /**
     * Read Excel data using ExcelReader and display preview.
     *
     * @param  FileSource  $source  The file source
     * @param  DetectedFormat  $format  The detected format
     * @return ExcelReader The Excel reader instance
     */
    private function readExcelData(FileSource $source, DetectedFormat $format): ExcelReader
    {
        /** @var ExcelReader */
        return $this->readDataWithPreview(
            new ExcelReader($source, $format),
            function (ReaderInterface $reader, int $rowCount) {
                $totalRows = $reader->getTotalRows();

                return "<fg=green>✓ Read</> <fg=yellow>{$rowCount}</> row(s) of <fg=yellow>{$totalRows}</> total";
            }
        );
    }

    /**
     * Read JSON Lines data using JsonLinesReader and display preview.
     *
     * @param  FileSource  $source  The file source
     * @param  DetectedFormat  $format  The detected format
     * @return JsonLinesReader The JSON Lines reader instance
     */
    private function readJsonData(FileSource $source, DetectedFormat $format): JsonLinesReader
    {
        /** @var JsonLinesReader */
        return $this->readDataWithPreview(
            new JsonLinesReader($source, $format),
            fn (ReaderInterface $reader, int $rowCount) => "<fg=green>✓ Read</> <fg=yellow>{$rowCount}</> row(s)"
        );
    }

    /**
     * Read XML data using XmlReader and display preview.
     *
     * @param  FileSource  $source  The file source
     * @param  DetectedFormat  $format  The detected format
     * @return XmlReader The XML reader instance
     */
    private function readXmlData(FileSource $source, DetectedFormat $format): XmlReader
    {
        /** @var XmlReader */
        return $this->readDataWithPreview(
            new XmlReader($source),
            fn (ReaderInterface $reader, int $rowCount) => "<fg=green>✓ Read</> <fg=yellow>{$rowCount}</> row(s)"
        );
    }

    /**
     * Read data with preview display (common logic for all reader types).
     *
     * @template T of ReaderInterface
     *
     * @param  T  $reader  The reader instance
     * @param  callable(ReaderInterface, int): string  $formatMessage  Callback to format the read count message
     * @return T The reader instance
     */
    private function readDataWithPreview(ReaderInterface $reader, callable $formatMessage): ReaderInterface
    {
        $previewRows = (int) $this->configResolver->resolveOptionWithFallback(
            ConfigKey::Preview->value,
            fn (string $key) => $this->command->option($key),
            5
        );

        // Business logic: read preview rows
        $previewData = $this->dataPreviewService->readPreview($reader, $previewRows);
        $rows = $previewData['rows'];
        $rowCount = $previewData['count'];

        // Presentation: display read count
        $this->command->infoLine($formatMessage($reader, $rowCount));

        // Presentation: display preview if not quiet
        if (! empty($rows) && ! $this->command->isQuiet()) {
            $this->displayPreview($reader, $rows, $previewRows);
        }

        return $reader;
    }

    /**
     * Display preview data (table or list format).
     *
     * @param  ReaderInterface  $reader  The reader instance
     * @param  array<int, array<string, mixed>>  $rows  The preview rows
     * @param  int  $previewRows  Number of preview rows
     */
    private function displayPreview(ReaderInterface $reader, array $rows, int $previewRows): void
    {
        $this->command->newLine();
        $this->command->line('<fg=cyan>Preview (first '.$previewRows.' rows)</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        // Business logic: format data
        $headers = $reader->getHeaders();
        $formatted = $this->dataPreviewFormatter->formatForTable($rows, $headers);

        // Presentation: display formatted data
        if ($formatted['has_headers']) {
            table($formatted['headers'], $formatted['table_data']);
        } else {
            $listData = $this->dataPreviewFormatter->formatForList($rows);
            foreach ($listData as $item) {
                $this->command->line('  <fg=gray>Row '.$item['row_number'].':</> '.$item['data']);
            }
        }

        $this->command->newLine();
    }
}
