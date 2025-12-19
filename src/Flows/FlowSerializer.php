<?php

namespace InFlow\Flows;

use InFlow\ValueObjects\Flow;

/**
 * Serializer for Flow persistence (file-based)
 *
 * Supports saving and loading Flow configurations to/from JSON files
 * for reuse across multiple executions.
 */
class FlowSerializer
{
    /**
     * Save Flow to file
     *
     * @param  Flow  $flow  The flow to save
     * @param  string  $filePath  Path to save the flow (JSON)
     * @return bool True on success
     */
    public function saveToFile(Flow $flow, string $filePath): bool
    {
        $data = $flow->toArray();
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension !== 'json') {
            throw new \RuntimeException("Unsupported file format: {$extension}. Use .json");
        }

        return $this->saveJson($data, $filePath);
    }

    /**
     * Load Flow from file
     *
     * @param  string  $filePath  Path to the flow file
     * @return Flow The loaded flow
     *
     * @throws \RuntimeException If file cannot be read or parsed
     */
    public function loadFromFile(string $filePath): Flow
    {
        if (! file_exists($filePath)) {
            throw new \RuntimeException("Flow file not found: {$filePath}");
        }

        if (! is_readable($filePath)) {
            throw new \RuntimeException("Flow file is not readable: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension !== 'json') {
            throw new \RuntimeException("Unsupported file format: {$extension}. Use .json");
        }

        $data = $this->loadJson($filePath);

        return Flow::fromArray($data);
    }

    /**
     * Get default storage directory for flows
     */
    public function getStorageDirectory(): string
    {
        $dir = storage_path('app/inflow/flows');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Get default file path for a flow by name
     */
    public function getDefaultPath(string $flowName): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $flowName);
        $dir = $this->getStorageDirectory();

        return $dir.'/'.$safeName.'.json';
    }

    /**
     * List all saved flows
     *
     * @return array<string> Array of flow file paths
     */
    public function listFlows(): array
    {
        $dir = $this->getStorageDirectory();
        $files = glob($dir.'/*.json');

        return $files ?: [];
    }

    /**
     * Check if a flow exists
     */
    public function flowExists(string $filePath): bool
    {
        return file_exists($filePath);
    }

    /**
     * Delete a flow file
     */
    public function deleteFlow(string $filePath): bool
    {
        if (! file_exists($filePath)) {
            return false;
        }

        return unlink($filePath);
    }

    /**
     * Save data as JSON
     */
    private function saveJson(array $data, string $filePath): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode Flow to JSON: '.json_last_error_msg());
        }

        $dir = dirname($filePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($filePath, $json) !== false;
    }

    /**
     * Load data from JSON
     */
    private function loadJson(string $filePath): array
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException("Failed to read Flow file: {$filePath}");
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse Flow JSON: '.json_last_error_msg());
        }

        return $data;
    }
}
