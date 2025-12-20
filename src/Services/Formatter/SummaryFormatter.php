<?php

namespace InFlow\Services\Formatter;

use InFlow\Enums\Data\TableHeader;
use InFlow\Services\File\FileWriterService;
use InFlow\ViewModels\SummaryViewModel;

/**
 * Formatter for processing summary
 */
readonly class SummaryFormatter
{
    public function __construct(
        private FileWriterService $fileWriter
    ) {}

    /**
     * Format summary data for display
     */
    public function format(int $lineCount, int $byteCount, float $duration): SummaryViewModel
    {
        $sizeFormatted = $this->fileWriter->formatSize($byteCount);
        $throughput = $this->calculateThroughput($byteCount, $duration);

        $headers = TableHeader::infoHeaders();
        $tableData = [
            ['Lines processed', (string) $lineCount],
            ['Bytes processed', $sizeFormatted],
            ['Processing time', "{$duration}s"],
            ['Throughput', $throughput],
        ];

        return new SummaryViewModel(
            title: 'Processing Summary',
            headers: $headers,
            tableData: $tableData,
            completionMessage: '✨ Processing completed successfully!',
        );
    }

    /**
     * Calculate and format throughput
     */
    private function calculateThroughput(int $byteCount, float $duration): string
    {
        if ($duration <= 0) {
            return 'N/A';
        }

        $throughput = number_format($byteCount / $duration, 0);

        return "{$throughput} bytes/s";
    }
}
