<?php

namespace InFlow\Services\Core;

use InFlow\Enums\ErrorPolicy;
use InFlow\Enums\SourceType;
use InFlow\ValueObjects\DetectedFormat;
use InFlow\ValueObjects\Flow;
use InFlow\ValueObjects\MappingDefinition;

/**
 * Service for building Flow objects from command parameters.
 *
 * Handles the business logic of constructing Flow configurations,
 * separating it from presentation concerns.
 */
readonly class FlowBuilderService
{
    public function __construct(
        private ConfigurationResolver $configResolver
    ) {}

    /**
     * Build a Flow from command parameters.
     *
     * @param  string  $filePath  The source file path
     * @param  MappingDefinition  $mapping  The mapping definition
     * @param  DetectedFormat  $format  The detected format (optional, for auto-detection)
     * @param  bool  $sanitize  Whether to apply sanitization
     * @param  array<string, mixed>  $sanitizerConfig  Sanitizer configuration
     * @return Flow The constructed Flow object
     */
    public function buildFlow(
        string $filePath,
        MappingDefinition $mapping,
        DetectedFormat $format,
        bool $sanitize,
        array $sanitizerConfig
    ): Flow {
        $sourceConfig = $this->buildSourceConfig($filePath);
        $flowOptions = $this->buildFlowOptions();
        $flowName = $this->buildFlowName($filePath);
        $flowDescription = 'Flow created from command execution';

        return new Flow(
            sourceConfig: $sourceConfig,
            sanitizerConfig: $sanitize ? $sanitizerConfig : [],
            formatConfig: null, // Auto-detect
            mapping: $mapping,
            options: $flowOptions,
            name: $flowName,
            description: $flowDescription
        );
    }

    /**
     * Build source configuration.
     *
     * @param  string  $filePath  The source file path
     * @return array<string, mixed> Source configuration
     */
    private function buildSourceConfig(string $filePath): array
    {
        return [
            'path' => $filePath,
            'type' => SourceType::File->value,
        ];
    }

    /**
     * Build flow execution options.
     *
     * @return array<string, mixed> Flow options
     */
    private function buildFlowOptions(): array
    {
        return [
            'chunk_size' => $this->configResolver->getReaderConfig('chunk_size', 1000),
            'error_policy' => ErrorPolicy::Continue->value,
        ];
    }

    /**
     * Build flow name from file path.
     *
     * @param  string  $filePath  The source file path
     * @return string Flow name
     */
    private function buildFlowName(string $filePath): string
    {
        return 'Command Flow: '.basename($filePath);
    }
}
