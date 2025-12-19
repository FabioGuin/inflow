<?php

namespace InFlow\Services\Loading\Strategies;

use Illuminate\Database\Eloquent\Relations\Relation;
use InFlow\Transforms\TransformEngine;

/**
 * Abstract base class for relation sync strategies.
 *
 * Provides common functionality shared across all relation types:
 * - Field extraction from relation items
 * - Field value finding with multiple key format support
 * - Transform application
 */
abstract class AbstractRelationSyncStrategy
{
    public function __construct(
        protected TransformEngine $transformEngine
    ) {}

    /**
     * Extract fields from relation item using multiple key formats.
     *
     * @param  array<string, mixed>  $relationItemArray  The relation item array
     * @param  array<string>  $fields  The fields to extract
     * @return array<string, mixed> The extracted fields
     */
    protected function extractFieldsFromRelationItem(array $relationItemArray, array $fields): array
    {
        $itemRelationData = [];

        foreach ($fields as $field) {
            $value = $this->findFieldValueInRelationItem($relationItemArray, $field);
            if ($value !== null) {
                $itemRelationData[$field] = $value;
            }
        }

        return $itemRelationData;
    }

    /**
     * Find field value in relation item using multiple key formats.
     *
     * Tries different key formats (snake_case, camelCase, PascalCase, etc.).
     *
     * @param  array<string, mixed>  $relationItemArray  The relation item array
     * @param  string  $field  The field name
     * @return mixed The value if found, null otherwise
     */
    protected function findFieldValueInRelationItem(array $relationItemArray, string $field): mixed
    {
        // 1. Exact match (snake_case)
        if (isset($relationItemArray[$field])) {
            return $relationItemArray[$field];
        }

        // 2. Try camelCase version (thumbnail_url -> thumbnailUrl)
        $camelField = lcfirst(str_replace('_', '', ucwords($field, '_')));
        if (isset($relationItemArray[$camelField])) {
            return $relationItemArray[$camelField];
        }

        // 3. Try PascalCase version
        $pascalField = str_replace('_', '', ucwords($field, '_'));
        if (isset($relationItemArray[$pascalField])) {
            return $relationItemArray[$pascalField];
        }

        // 4. Try without underscores (thumbnail_url -> thumbnailurl)
        $noUnderscoreField = str_replace('_', '', $field);
        if (isset($relationItemArray[$noUnderscoreField])) {
            return $relationItemArray[$noUnderscoreField];
        }

        // 5. Try case-insensitive match
        foreach ($relationItemArray as $key => $fieldValue) {
            if (strtolower($key) === strtolower($field) ||
                strtolower(str_replace('_', '', $key)) === strtolower(str_replace('_', '', $field))) {
                return $fieldValue;
            }
        }

        return null;
    }

    /**
     * Apply transforms to a value using the TransformEngine.
     */
    protected function applyTransformsToValue(mixed $value, array $transforms): mixed
    {
        if (empty($transforms)) {
            return $value;
        }

        return $this->transformEngine->apply($value, $transforms);
    }

    /**
     * Check if relation data is empty (contains no real payload, only meta or null/empty values).
     */
    protected function isEmptyRelationData(array $relationData): bool
    {
        $payload = $relationData;

        unset($payload['__array_data'], $payload['__fields'], $payload['__pivot']);

        if (empty($payload)) {
            return true;
        }

        foreach ($payload as $fieldValue) {
            if ($fieldValue !== null && $fieldValue !== '') {
                return false;
            }
        }

        return true;
    }
}

