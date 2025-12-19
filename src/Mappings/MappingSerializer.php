<?php

namespace InFlow\Mappings;

use InFlow\ValueObjects\MappingDefinition;

/**
 * Serializer for mapping definitions to/from JSON
 */
class MappingSerializer
{
    /**
     * Serialize mapping definition to JSON
     */
    public function toJson(MappingDefinition $mapping, bool $pretty = true): string
    {
        $data = $mapping->toArray();
        $data['created_at'] = $data['created_at'] ?? now()->toIso8601String();
        $data['updated_at'] = $data['updated_at'] ?? now()->toIso8601String();

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($data, $flags);
    }

    /**
     * Deserialize mapping definition from JSON
     */
    public function fromJson(string $json): MappingDefinition
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: '.json_last_error_msg());
        }

        return MappingDefinition::fromArray($data);
    }

    /**
     * Save mapping definition to a JSON file
     */
    public function saveToFile(MappingDefinition $mapping, string $filepath): void
    {
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

        if ($extension !== 'json') {
            throw new \InvalidArgumentException("Unsupported file format: {$extension}. Use .json");
        }

        $content = $this->toJson($mapping);

        $directory = dirname($filepath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($filepath, $content);
    }

    /**
     * Load mapping definition from a JSON file
     */
    public function loadFromFile(string $filepath): MappingDefinition
    {
        if (! file_exists($filepath)) {
            throw new \InvalidArgumentException("File not found: {$filepath}");
        }

        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

        if ($extension !== 'json') {
            throw new \InvalidArgumentException("Unsupported file format: {$extension}. Use .json");
        }

        $content = file_get_contents($filepath);

        return $this->fromJson($content);
    }
}
