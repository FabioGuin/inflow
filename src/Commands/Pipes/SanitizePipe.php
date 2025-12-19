<?php

namespace InFlow\Commands\Pipes;

use Closure;
use InFlow\Commands\InFlowCommandContext;
use InFlow\Constants\DisplayConstants;
use InFlow\Contracts\ProcessingPipeInterface;
use InFlow\Contracts\SanitizationReportInterface;
use InFlow\Enums\ConfigKey;
use InFlow\Enums\NewlineFormat;
use InFlow\Enums\TableHeader;
use InFlow\Sanitizers\SanitizerConfigKeys;
use InFlow\Services\Core\ConfigurationResolver;
use InFlow\Services\DataProcessing\ContentUtilityService;
use InFlow\Services\DataProcessing\SanitizationService;
use InFlow\Services\Formatter\ReportFormatterService;
use InFlow\ValueObjects\ProcessingContext;

/**
 * Third step of the ETL pipeline: sanitize raw file content.
 *
 * Determines whether sanitization should be performed based on command options,
 * guided configuration, or config file defaults. If enabled, sanitizes the content
 * by removing BOM, normalizing newlines, and removing control characters.
 *
 * Any exceptions thrown during sanitization will be caught and handled by the
 * exception handler in InFlowCommand::handle().
 */
readonly class SanitizePipe implements ProcessingPipeInterface
{
    public function __construct(
        private InFlowCommandContext $command,
        private ConfigurationResolver $configResolver,
        private SanitizationService $sanitizationService,
        private ReportFormatterService $reportFormatter,
        private ContentUtilityService $contentUtility
    ) {}

    /**
     * Sanitize file content if enabled and update processing context.
     *
     * Determines sanitization requirement through multiple sources (command option,
     * guided config, config file, or interactive prompt). If sanitization is enabled,
     * processes the content and updates line count if normalization occurred.
     *
     * @param  ProcessingContext  $context  The processing context containing the file content
     * @param  Closure  $next  The next pipe in the pipeline
     * @return ProcessingContext Updated context with sanitized content and sanitization flag
     */
    public function handle(ProcessingContext $context, Closure $next): ProcessingContext
    {
        if ($context->cancelled) {
            return $next($context);
        }

        if ($context->content === null) {
            return $next($context);
        }

        // Determine if sanitization should be performed
        [$shouldSanitize, $context] = $this->determineShouldSanitize($context);

        if ($shouldSanitize) {
            $this->command->infoLine('<fg=blue>Step 3/9:</> <fg=gray>Sanitizing content...</>');
            $this->command->note('Cleaning file: removing BOM, normalizing newlines, removing control characters.');

            $lineCount = $context->lineCount ?? 0;
            $content = $this->sanitizeContent($context->content);
            $newLineCount = $this->contentUtility->countLines($content);

            if ($newLineCount !== $lineCount && ! $this->command->isQuiet()) {
                $this->command->note("Line count changed: {$lineCount} → {$newLineCount} (normalization effect)", 'warning');
            }

            $context = $context
                ->withContent($content)
                ->withLineCount($newLineCount)
                ->withShouldSanitize(true);

            // Checkpoint after sanitization
            $checkpointResult = $this->command->checkpoint('Sanitization', [
                'Lines' => (string) $newLineCount,
                'Status' => 'cleaned',
            ]);

            if ($checkpointResult === 'cancel') {
                return $next($context->withCancelled());
            }
        } else {
            $this->command->warning('Sanitization skipped');
            $context = $context->withShouldSanitize(false);
        }


        return $next($context);
    }

    /**
     * Sanitize content with configuration display and report.
     *
     * Presentation layer: displays sanitizer configuration and report.
     * Business logic is delegated to SanitizationService.
     *
     * @param  string  $content  The raw content to sanitize
     * @return string The sanitized content
     */
    private function sanitizeContent(string $content): string
    {
        $config = $this->getSanitizerConfig();

        // Display sanitizer configuration (presentation)
        $this->displaySanitizerConfig($config);

        // Perform sanitization (business logic)
        [$sanitized, $report] = $this->sanitizationService->sanitize($content, $config);

        // Display results (presentation)
        $this->command->success('Sanitization completed');
        $this->displaySanitizationReport($report);

        return $sanitized;
    }

    /**
     * Get sanitizer configuration.
     *
     * Builds configuration from config file and command options.
     *
     * @return array<string, mixed> The sanitizer configuration array
     */
    private function getSanitizerConfig(): array
    {
        return $this->configResolver->buildSanitizerConfig(
            fn (string $key) => $this->command->getOption($key)
        );
    }

    /**
     * Display sanitizer configuration settings.
     *
     * Shows which sanitization options are enabled/disabled.
     *
     * @param  array  $config  The sanitizer configuration array
     */
    private function displaySanitizerConfig(array $config): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $this->command->line('  <fg=gray>→</> Configuration:');
        $this->command->line('    • BOM removal: '.($config[SanitizerConfigKeys::RemoveBom] ?? true ? '<fg=green>enabled</>' : '<fg=red>disabled</>'));

        $this->command->line('    • Newline normalization: '.($config[SanitizerConfigKeys::NormalizeNewlines] ?? true ? '<fg=green>enabled</>' : '<fg=red>disabled</>'));

        if (isset($config[SanitizerConfigKeys::NewlineFormat])) {
            $formatChar = $config[SanitizerConfigKeys::NewlineFormat];
            $format = NewlineFormat::fromCharacter($formatChar);
            $formatName = $format !== null ? $format->getDisplayName() : 'unknown';
            $this->command->line('    • Newline format: <fg=yellow>'.$formatName.'</>');
        }

        $this->command->line('    • Control chars removal: '.($config[SanitizerConfigKeys::RemoveControlChars] ?? true ? '<fg=green>enabled</>' : '<fg=red>disabled</>'));
        $this->command->line('    • EOF handling: '.($config[SanitizerConfigKeys::HandleTruncatedEof] ?? true ? '<fg=green>enabled</>' : '<fg=red>disabled</>'));
    }

    /**
     * Display sanitization report.
     *
     * Presentation layer: displays formatted report data.
     * Business logic (formatting) is delegated to ReportFormatterService.
     *
     * @param  SanitizationReportInterface  $report  The sanitization report
     */
    private function displaySanitizationReport(SanitizationReportInterface $report): void
    {
        if ($this->command->isQuiet()) {
            return;
        }

        $formatted = $this->reportFormatter->formatSanitizationReport($report);

        if (! $formatted['has_content']) {
            $this->command->success('No sanitization actions were needed. File is clean.');
            $this->command->newLine();

            return;
        }

        $this->command->newLine();
        $this->command->infoLine('Sanitization Report');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);

        if (! empty($formatted['statistics'])) {
            $this->command->newLine();
            $this->command->line('<fg=cyan>Statistics:</>');
            $statsTable = array_map(fn ($stat) => [$stat['label'], '<fg=yellow>'.$stat['value'].'</>'], $formatted['statistics']);
            $this->command->table(TableHeader::reportHeaders(), $statsTable);
        }

        if (! empty($formatted['decisions'])) {
            $this->command->newLine();
            $this->command->line('<fg=cyan>Actions taken:</>');
            foreach ($formatted['decisions'] as $decision) {
                $this->command->line("  <fg=green>✓</> {$decision}");
            }
        }

        if (! empty($formatted['affected_rows'])) {
            $this->command->newLine();
            $this->command->line('<fg=cyan>Affected rows (examples):</>');
            foreach ($formatted['affected_rows'] as $row) {
                $this->command->line("  <fg=yellow>→</> {$row}");
            }

            $remaining = $this->reportFormatter->getRemainingAffectedRowsCount($report->getAffectedRows());
            if ($remaining > 0) {
                $this->command->line("  <fg=gray>... and {$remaining} more</>");
            }
        }

        $this->command->newLine();
    }

    /**
     * Determine if sanitization should be performed.
     *
     * Checks multiple sources in order of priority:
     * 1. Explicit command option (--sanitize=value, accepts: 1/0, true/false, y/n)
     * 2. Guided configuration from setup wizard
     * 3. Config file default
     * 4. Quiet mode default (true)
     * 5. Interactive prompt (if not in quiet/no-interaction mode)
     * 6. Non-interactive default (false)
     *
     * @param  ProcessingContext  $context  The processing context
     * @return array{0: bool, 1: ProcessingContext} Tuple of [should sanitize, updated context]
     */
    private function determineShouldSanitize(ProcessingContext $context): array
    {
        $sanitizeOptionValue = $this->command->option(ConfigKey::Sanitize->value);
        $wasSanitizePassed = $this->command->hasParameterOption('--'.ConfigKey::Sanitize->value, true);

        // If option was passed explicitly, parse the value
        if ($wasSanitizePassed) {
            return [$this->parseBooleanValue($sanitizeOptionValue), $context];
        }

        // Check guided config
        if (isset($context->guidedConfig[ConfigKey::Sanitize->value])) {
            return [(bool) $context->guidedConfig[ConfigKey::Sanitize->value], $context];
        }

        // Use config default
        $configDefault = $this->configResolver->getConfigDefault(ConfigKey::Sanitize->value);
        if ($configDefault !== null) {
            return [(bool) $configDefault, $context];
        }

        // Quiet mode: default to true for sanitization if not in config
        if ($this->command->isQuiet()) {
            return [true, $context];
        }

        // Ask interactively if not specified
        if (! $this->command->option('no-interaction')) {
            $this->command->infoLine('<fg=blue>Step 3/9:</> <fg=gray>Sanitization...</>');
            $shouldSanitize = $this->command->confirm('  Do you want to sanitize the file (remove BOM, normalize newlines, etc.)?', true);
            $guidedConfig = $context->guidedConfig;
            $guidedConfig[ConfigKey::Sanitize->value] = $shouldSanitize;
            $context = $context->withGuidedConfig($guidedConfig);

            return [$shouldSanitize, $context];
        }

        // Non-interactive mode: default to false
        return [false, $context];
    }

    /**
     * Parse a value to boolean, handling various string representations.
     *
     * Supports: 1/0, true/false, y/n, on/off, and empty string (treated as true for compatibility).
     *
     * @param  mixed  $value  The value to parse
     * @return bool The parsed boolean value
     */
    private function parseBooleanValue(mixed $value): bool
    {
        // If already boolean, return as is
        if (is_bool($value)) {
            return $value;
        }

        // If null or empty string, treat as true (for compatibility with --sanitize without value)
        if ($value === null || $value === '') {
            return true;
        }

        // Convert to string and normalize
        $normalized = strtolower(trim((string) $value));

        // Check for truthy values
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'enabled'], true);
    }
}
