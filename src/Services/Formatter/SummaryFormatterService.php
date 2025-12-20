<?php

namespace InFlow\Services\Formatter;

use InFlow\Enums\Data\TableHeader;
use InFlow\Services\File\FileWriterService;

/**
 * Service for formatting summary data for display.
 *
 * Handles the business logic of formatting summary information for presentation.
 * Presentation logic (actual output) is handled by the caller.
 */
readonly class SummaryFormatterService
{
    public function __construct(
        private FileWriterService $fileWriter
    ) {}

    /**
     * Format summary data for table display.
     *
     * @param  int  $lineCount  Number of lines processed
     * @param  int  $byteCount  Number of bytes processed
     * @param  float  $duration  Processing duration in seconds
     * @return array{headers: array<string>, table_data: array<int, array<int, string>>}
     */
    public function formatForTable(int $lineCount, int $byteCount, float $duration): array
    {
        $sizeFormatted = $this->fileWriter->formatSize($byteCount);
        $throughput = $this->calculateThroughput($byteCount, $duration);

        return [
            'headers' => TableHeader::infoHeaders(),
            'table_data' => [
                ['Lines processed', "<fg=yellow>{$lineCount}</>"],
                ['Bytes processed', "<fg=yellow>{$sizeFormatted}</>"],
                ['Processing time', "<fg=yellow>{$duration}s</>"],
                ['Throughput', "<fg=yellow>{$throughput}</>"],
            ],
        ];
    }

    /**
     * Calculate and format throughput.
     *
     * @param  int  $byteCount  Number of bytes processed
     * @param  float  $duration  Processing duration in seconds
     * @return string Formatted throughput (e.g., "1,234 bytes/s")
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
