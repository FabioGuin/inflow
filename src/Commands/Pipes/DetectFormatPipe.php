<?php

namespace InFlow\Commands\Pipes;

use Closure;
use InFlow\Commands\InFlowCommandContext;
use InFlow\Constants\DisplayConstants;
use InFlow\Contracts\ProcessingPipeInterface;
use InFlow\Detectors\FormatDetector;
use InFlow\Enums\TableHeader;
use InFlow\Services\Formatter\FormatFormatterService;
use InFlow\ValueObjects\DetectedFormat;
use InFlow\ValueObjects\ProcessingContext;

/**
 * Fourth step of the ETL pipeline: detect file format.
 *
 * Analyzes the file structure to detect format type, delimiter, encoding,
 * and header presence. Displays format information to the user.
 */
readonly class DetectFormatPipe implements ProcessingPipeInterface
{
    public function __construct(
        private InFlowCommandContext $command,
        private FormatDetector $formatDetector,
        private FormatFormatterService $formatFormatter
    ) {}

    /**
     * Detect file format and update processing context.
     *
     * Analyzes the file structure and displays format information to the user.
     *
     * @param  ProcessingContext  $context  The processing context containing the FileSource
     * @param  Closure  $next  The next pipe in the pipeline
     * @return ProcessingContext Updated context with detected format
     */
    public function handle(ProcessingContext $context, Closure $next): ProcessingContext
    {
        if ($context->cancelled) {
            return $next($context);
        }

        if ($context->source === null) {
            return $next($context);
        }

        $this->command->infoLine('<fg=blue>Step 4/9:</> <fg=gray>Detecting file format...</>');

        $this->command->note('Analyzing file structure to detect format, delimiter, encoding, and header presence.');


        $format = $this->formatDetector->detect($context->source);
        $context = $context->withFormat($format);

        $this->command->success('Format detected successfully');

        if (! $this->command->isQuiet()) {
            $this->displayFormatInfo($format);
        }

        // Checkpoint: Allow user to review format detection
        if (! $this->command->checkpoint('Format detected: '.$format->type->value)) {
            $context->cancelled = true;

            return $context;
        }

        return $next($context);
    }

    /**
     * Display format information to the user.
     *
     * Shows format type, delimiter, encoding, and header presence in a formatted table.
     *
     * @param  DetectedFormat  $format  The detected format
     */
    private function displayFormatInfo(DetectedFormat $format): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=cyan>Format Information</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        $formattedData = $this->formatFormatter->formatForTable($format);
        $tableRows = array_map(
            fn (array $row) => [$row['property'], $row['value']],
            $formattedData
        );

        $this->command->table(
            TableHeader::infoHeaders(),
            $tableRows
        );
        $this->command->newLine();
    }
}
