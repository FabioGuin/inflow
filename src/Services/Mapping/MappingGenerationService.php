<?php

namespace InFlow\Services\Mapping;

use InFlow\Mappings\MappingSerializer;
use InFlow\Services\Core\ConfigurationResolver;
use InFlow\ValueObjects\MappingDefinition;
use InFlow\ValueObjects\ModelMapping;

/**
 * Service for mapping generation business logic.
 *
 * Handles the business logic of mapping generation, including:
 * - Generating mapping names and descriptions
 * - Ensuring duplicate handling options are present
 * - Saving mappings to files
 *
 * Presentation logic (prompts, output) is handled by the caller.
 */
readonly class MappingGenerationService
{
    public function __construct(
        private MappingSerializer $mappingSerializer,
        private ConfigurationResolver $configResolver
    ) {}

    /**
     * Generate mapping name from model class and file path.
     *
     * @param  string  $modelClass  The model class name
     * @param  string|null  $filePath  The source file path
     * @return string The generated mapping name
     */
    public function generateMappingName(string $modelClass, ?string $filePath): string
    {
        $fileName = $filePath !== null ? basename($filePath) : 'unknown';
        $modelShortName = class_basename($modelClass);

        return "{$modelShortName} - {$fileName}";
    }

    /**
     * Generate mapping description from model class and file path.
     *
     * @param  string  $modelClass  The model class name
     * @param  string|null  $filePath  The source file path
     * @return string The generated mapping description
     */
    public function generateMappingDescription(string $modelClass, ?string $filePath): string
    {
        $fileName = $filePath !== null ? basename($filePath) : 'unknown';
        $modelShortName = class_basename($modelClass);

        return "Mapping for {$modelShortName} model from {$fileName}";
    }

    /**
     * Ensure duplicate handling options are present in mapping.
     *
     * If options are missing, attempts to detect and add them.
     * Returns a new MappingDefinition with guaranteed options.
     *
     * @param  MappingDefinition  $mapping  The mapping to ensure options for
     * @param  string  $modelClass  The model class name
     * @param  callable  $detectUniqueKeys  Callback to detect unique keys (modelClass, table) => array
     * @return MappingDefinition The mapping with guaranteed options
     */
    public function ensureDuplicateHandlingOptions(
        MappingDefinition $mapping,
        string $modelClass,
        callable $detectUniqueKeys
    ): MappingDefinition {
        $finalMappings = [];

        foreach ($mapping->mappings as $modelMapping) {
            $finalOptions = $modelMapping->options ?? [];

            // Check if options are missing or incomplete
            if (empty($finalOptions)
                || empty($finalOptions['unique_key'])
                || empty($finalOptions['duplicate_strategy'])) {
                // Try to detect unique keys
                try {
                    $tempModel = new $modelClass;
                    $table = $tempModel->getTable();
                    $uniqueKeys = $detectUniqueKeys($modelClass, $table);

                    if (! empty($uniqueKeys)) {
                        $finalOptions = [
                            'unique_key' => $uniqueKeys[0],
                            'duplicate_strategy' => 'update',
                        ];
                    } else {
                        $finalOptions = [];
                    }
                } catch (\Exception $e) {
                    \inflow_report($e, 'debug', ['operation' => 'detectUniqueKeysForMapping']);
                    $finalOptions = [];
                }
            }

            // Rebuild ModelMapping with guaranteed options
            $finalMappings[] = new ModelMapping(
                modelClass: $modelMapping->modelClass,
                columns: $modelMapping->columns,
                options: $finalOptions
            );
        }

        // Create final MappingDefinition with guaranteed options
        return new MappingDefinition(
            mappings: $finalMappings,
            name: $mapping->name,
            description: $mapping->description,
            sourceSchema: $mapping->sourceSchema
        );
    }

    /**
     * Get mapping save path from model class.
     *
     * @param  string  $modelClass  The model class name
     * @return string The path where to save the mapping
     */
    public function getMappingSavePath(string $modelClass): string
    {
        return $this->configResolver->getMappingPathFromModel($modelClass);
    }

    /**
     * Save mapping to file.
     *
     * @param  MappingDefinition  $mapping  The mapping to save
     * @param  string  $mappingPath  The path where to save the mapping
     *
     * @throws \Exception If the mapping cannot be saved
     */
    public function saveMapping(MappingDefinition $mapping, string $mappingPath): void
    {
        $this->mappingSerializer->saveToFile($mapping, $mappingPath);
    }

    /**
     * Verify that duplicate handling options are present in mapping.
     *
     * @param  MappingDefinition  $mapping  The mapping to verify
     * @return bool True if options are present, false otherwise
     */
    public function hasDuplicateHandlingOptions(MappingDefinition $mapping): bool
    {
        $firstMapping = $mapping->mappings[0] ?? null;
        if ($firstMapping === null) {
            return false;
        }

        $options = $firstMapping->options ?? [];

        return ! empty($options['unique_key'])
            && ! empty($options['duplicate_strategy']);
    }
}
