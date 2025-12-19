<?php

namespace InFlow\Sanitizers;

use InFlow\Contracts\SanitizationReportInterface;
use InFlow\Contracts\SanitizerInterface;
use InFlow\Enums\BomType;
use InFlow\Enums\NewlineFormat;
use InFlow\Services\Core\ConfigurationResolver;

/**
 * Sanitizes raw file content by removing BOM, normalizing newlines,
 * and handling control characters and encoding issues.
 */
class RawSanitizer implements SanitizerInterface
{
    /**
     * Regex pattern for control characters to remove.
     *
     * Removes control characters except:
     * - \x09 (TAB)
     * - \x0A (LF - Line Feed)
     * - \x0D (CR - Carriage Return)
     */
    private const CONTROL_CHARS_PATTERN = '/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/';

    private array $statistics = [];

    private array $decisions = [];

    private array $affectedRows = [];

    private ?array $config = null;

    /**
     * @param  ConfigurationResolver  $configResolver  Configuration resolver (injected via DI)
     * @param  array  $config  Optional default configuration (for backward compatibility)
     */
    public function __construct(
        private readonly ConfigurationResolver $configResolver,
        array $config = []
    ) {
        if (! empty($config)) {
            $this->config = array_merge($this->configResolver->getDefaultSanitizerConfig(), $config);
        }
    }

    /**
     * Sanitizes raw content and returns cleaned content.
     *
     * @param  string  $content  The raw content to sanitize
     * @param  array|null  $config  Optional configuration array. If not provided, uses config from constructor or defaults.
     * @return string The sanitized content
     */
    public function sanitize(string $content, ?array $config = null): string
    {
        $this->resetReport();

        // Use provided config, constructor config, or defaults
        $activeConfig = $config !== null
            ? $this->mergeConfig($config)
            : ($this->config ?? $this->configResolver->getDefaultSanitizerConfig());

        $sanitized = $content;

        // Remove BOM
        if ($activeConfig[SanitizerConfigKeys::RemoveBom] ?? false) {
            $sanitized = $this->removeBom($sanitized);
        }

        // Normalize newlines
        if ($activeConfig[SanitizerConfigKeys::NormalizeNewlines] ?? false) {
            $sanitized = $this->normalizeNewlines($sanitized, $activeConfig);
        }

        // Remove control characters
        if ($activeConfig[SanitizerConfigKeys::RemoveControlChars] ?? false) {
            $sanitized = $this->removeControlChars($sanitized);
        }

        // Handle truncated EOF
        if ($activeConfig[SanitizerConfigKeys::HandleTruncatedEof] ?? false) {
            $sanitized = $this->handleTruncatedEof($sanitized, $activeConfig);
        }

        return $sanitized;
    }

    /**
     * Merge provided config with defaults.
     *
     * @param  array  $config  Provided configuration
     * @return array<string, mixed> Merged configuration
     */
    private function mergeConfig(array $config): array
    {
        return array_merge($this->configResolver->getDefaultSanitizerConfig(), $config);
    }

    /**
     * Returns a report of the sanitization operations performed
     */
    public function getReport(): SanitizationReportInterface
    {
        return new SanitizationReport(
            statistics: $this->statistics,
            decisions: $this->decisions,
            affectedRows: $this->affectedRows
        );
    }

    /**
     * Remove BOM markers from content.
     *
     * Uses BomType enum to detect and remove BOM markers.
     *
     * @param  string  $content  The content to process
     * @return string The content with BOM removed if present
     */
    private function removeBom(string $content): string
    {
        $detectedBom = BomType::detect($content);

        if ($detectedBom === null) {
            return $content;
        }

        $bomLength = $detectedBom->getLength();
        $content = substr($content, $bomLength);

        $this->decisions[] = sprintf(SanitizerMessages::BomRemoved, $detectedBom->getName());
        $this->statistics[SanitizerStatisticsKeys::BomRemoved] = ($this->statistics[SanitizerStatisticsKeys::BomRemoved] ?? 0) + 1;
        $this->statistics[SanitizerStatisticsKeys::BomBytesRemoved] = ($this->statistics[SanitizerStatisticsKeys::BomBytesRemoved] ?? 0) + $bomLength;

        return $content;
    }

    /**
     * Normalize newlines to configured format.
     *
     * Uses NewlineFormat enum to determine the target format.
     *
     * @param  string  $content  The content to normalize
     * @param  array  $config  The configuration array
     * @return string The normalized content
     */
    private function normalizeNewlines(string $content, array $config): string
    {
        $originalContent = $content;

        // Remove CRLF first to avoid double counting
        $content = str_replace(NewlineFormat::Crlf->getCharacter(), NewlineFormat::Lf->getCharacter(), $content);
        // Then remove standalone CR
        $content = str_replace(NewlineFormat::Cr->getCharacter(), NewlineFormat::Lf->getCharacter(), $content);

        // Determine target format from config
        $targetFormat = $this->getNewlineFormatFromConfig($config[SanitizerConfigKeys::NewlineFormat]);

        // Convert to configured format if different from LF
        $targetCharacter = $targetFormat->getCharacter();
        if ($targetCharacter !== NewlineFormat::Lf->getCharacter()) {
            $content = str_replace(NewlineFormat::Lf->getCharacter(), $targetCharacter, $content);
        }

        // Check if normalization occurred by comparing original and normalized content
        if ($originalContent !== $content) {
            $formatName = $targetFormat->getDisplayName();
            $this->decisions[] = sprintf(SanitizerMessages::NewlinesNormalized, $formatName);
            $this->statistics[SanitizerStatisticsKeys::NewlinesNormalized] = ($this->statistics[SanitizerStatisticsKeys::NewlinesNormalized] ?? 0) + 1;
        }

        return $content;
    }

    /**
     * Get NewlineFormat enum from config value.
     *
     * Handles both string characters and enum values.
     *
     * @param  string  $formatValue  The format value from config (character or enum value)
     * @return NewlineFormat The corresponding enum
     */
    private function getNewlineFormatFromConfig(string $formatValue): NewlineFormat
    {
        // If it's already a character, find matching enum
        return match ($formatValue) {
            NewlineFormat::Lf->getCharacter() => NewlineFormat::Lf,
            NewlineFormat::Crlf->getCharacter() => NewlineFormat::Crlf,
            NewlineFormat::Cr->getCharacter() => NewlineFormat::Cr,
            default => NewlineFormat::tryFrom($formatValue) ?? NewlineFormat::Lf,
        };
    }

    /**
     * Remove control characters (except newlines and tabs).
     *
     * Removes control characters using a predefined regex pattern.
     * Preserves TAB (\x09), LF (\x0A), and CR (\x0D).
     *
     * @param  string  $content  The content to process
     * @return string The content with control characters removed
     */
    private function removeControlChars(string $content): string
    {
        $originalLength = strlen($content);

        // Remove control characters except TAB, LF, and CR
        $sanitized = preg_replace(self::CONTROL_CHARS_PATTERN, '', $content);

        $removed = $originalLength - strlen($sanitized);

        if ($removed > 0) {
            $this->statistics[SanitizerStatisticsKeys::ControlCharsRemoved] = ($this->statistics[SanitizerStatisticsKeys::ControlCharsRemoved] ?? 0) + $removed;
            $this->decisions[] = sprintf(SanitizerMessages::ControlCharsRemoved, $removed);
        }

        return $sanitized;
    }

    /**
     * Handle truncated EOF (ensure file ends with newline if it should).
     *
     * Uses NewlineFormat enum to determine the correct newline character.
     *
     * @param  string  $content  The content to process
     * @param  array  $config  The configuration array
     * @return string The content with EOF fixed if needed
     */
    private function handleTruncatedEof(string $content, array $config): string
    {
        if (empty($content)) {
            return $content;
        }

        $targetFormat = $this->getNewlineFormatFromConfig($config[SanitizerConfigKeys::NewlineFormat]);
        $targetCharacter = $targetFormat->getCharacter();

        // Check if content already ends with any newline variant
        $endsWithNewline = str_ends_with($content, NewlineFormat::Lf->getCharacter())
            || str_ends_with($content, NewlineFormat::Crlf->getCharacter())
            || str_ends_with($content, NewlineFormat::Cr->getCharacter())
            || str_ends_with($content, $targetCharacter);

        if (! $endsWithNewline) {
            $content .= $targetCharacter;
            $this->decisions[] = SanitizerMessages::EofFixed;
            $this->statistics[SanitizerStatisticsKeys::EofFixed] = ($this->statistics[SanitizerStatisticsKeys::EofFixed] ?? 0) + 1;
        }

        return $content;
    }

    /**
     * Reset report data
     */
    private function resetReport(): void
    {
        $this->statistics = [];
        $this->decisions = [];
        $this->affectedRows = [];
    }
}
