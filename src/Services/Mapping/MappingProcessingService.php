<?php

namespace InFlow\Services\Mapping;

use InFlow\Mappings\MappingSerializer;
use InFlow\ValueObjects\MappingDefinition;

/**
 * Service for mapping processing business logic.
 *
 * Handles the business logic of mapping processing, including:
 * - Loading mappings from files
 * - Checking duplicate handling configuration
 * - Saving updated mappings
 *
 * Presentation logic (prompts, output) is handled by the caller.
 */
readonly class MappingProcessingService
{
    public function __construct(
        private MappingSerializer $mappingSerializer
    ) {}

    /**
     * Load mapping from file path.
     *
     * @param  string  $mappingPath  The path to the mapping file
     * @return MappingDefinition The loaded mapping
     *
     * @throws \Exception If the mapping cannot be loaded
     */
    public function loadMapping(string $mappingPath): MappingDefinition
    {
        return $this->mappingSerializer->loadFromFile($mappingPath);
    }

    /**
     * Check if mapping has duplicate handling configured.
     *
     * @param  MappingDefinition  $mapping  The mapping to check
     * @return bool True if duplicate handling is configured, false otherwise
     */
    public function isDuplicateHandlingConfigured(MappingDefinition $mapping): bool
    {
        $firstMapping = $mapping->mappings[0] ?? null;
        if ($firstMapping === null) {
            return false;
        }

        $options = $firstMapping->options ?? [];

        return ! empty($options['unique_key'])
            && ! empty($options['duplicate_strategy']);
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
}
