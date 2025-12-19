<?php

namespace InFlow\Services\Loading;

use InFlow\Enums\EloquentRelationType;
use InFlow\ValueObjects\ColumnMapping;
use InFlow\ValueObjects\ModelMapping;
use InFlow\ValueObjects\Row;

/**
 * Service for grouping column mappings into attributes and relations.
 *
 * Handles business logic for:
 * - Extracting values from rows
 * - Grouping direct attributes vs nested relations
 * - Delegating relation lookup configuration to RelationLookupService
 * - Handling optional relation fields
 * - Managing array data for HasMany/BelongsToMany relations
 * - Handling pivot data for BelongsToMany relations
 *
 * Presentation logic (logging, errors) is handled by the caller.
 */
readonly class AttributeGroupingService
{
    public function __construct(
        private ColumnValueService $columnValueService,
        private ColumnValidationService $columnValidationService,
        private RelationTypeService $relationTypeService,
        private RelationLookupService $relationLookupService
    ) {}

    /**
     * Group column mappings into attributes and relations.
     *
     * Business logic: processes all column mappings and groups them into attributes and relations.
     *
     * @param  Row  $row  The source row
     * @param  ModelMapping  $mapping  The model mapping
     * @return array{attributes: array<string, mixed>, relations: array<string, array{data: array, lookup: array|null, pivot: array<string, mixed>}>, truncatedFields: array<int, array{field: string, original_length: int, max_length: int}>} Grouped attributes and relations
     */
    public function groupAttributesAndRelations(Row $row, ModelMapping $mapping): array
    {
        $attributes = [];
        $relations = [];
        $truncatedFields = [];

        foreach ($mapping->columns as $columnMapping) {
            // Extract and transform value
            $transformedValue = $this->columnValueService->extractValue($row, $columnMapping);

            // Parse path (e.g., "address.street" -> ["address", "street"])
            $pathParts = $this->splitTargetPathIntoParts($columnMapping->targetPath);

            // Validate and truncate string values that exceed column max length
            if (count($pathParts) === 1 && is_string($transformedValue) && $transformedValue !== '') {
                $validationResult = $this->columnValidationService->validateAndTruncate(
                    $mapping->modelClass,
                    $pathParts[0],
                    $transformedValue
                );

                $transformedValue = $validationResult['value'];
                if ($validationResult['truncated'] && $validationResult['details'] !== null) {
                    $truncatedFields[] = $validationResult['details'];
                }
            }

            if (count($pathParts) === 1) {
                // Direct attribute
                $attributes[$pathParts[0]] = $transformedValue;
            } else {
                // Nested relation
                $this->addToRelation($relations, $pathParts, $transformedValue, $columnMapping, $mapping);
            }
        }

        return [
            'attributes' => $attributes,
            'relations' => $relations,
            'truncatedFields' => $truncatedFields,
        ];
    }

    /**
     * Add value to relation data structure.
     *
     * Business logic: routes to appropriate handler based on path structure.
     */
    private function addToRelation(
        array &$relations,
        array $pathParts,
        mixed $transformedValue,
        ColumnMapping $columnMapping,
        ModelMapping $mapping
    ): void {
        $relationName = $pathParts[0];
        $this->ensureRelationExists($relations, $relationName);

        // Route to appropriate handler
        if ($pathParts[1] === '*') {
            $this->handleFullArrayMapping($relations, $relationName, $transformedValue, $columnMapping, $mapping);

            return;
        }

        if ($pathParts[1] === 'pivot' && isset($pathParts[2])) {
            $this->handlePivotMapping($relations, $relationName, $pathParts[2], $transformedValue);

            return;
        }

        $this->handleNormalRelationField($relations, $relationName, $pathParts[1], $transformedValue, $columnMapping, $mapping);
    }

    /**
     * Ensure relation structure exists.
     */
    private function ensureRelationExists(array &$relations, string $relationName): void
    {
        if (! isset($relations[$relationName])) {
            $relations[$relationName] = [
                'data' => [],
                'lookup' => null,
                'pivot' => [],
                'field_transforms' => [],
            ];
        }
    }

    /**
     * Handle full array mapping: relation.*
     */
    private function handleFullArrayMapping(
        array &$relations,
        string $relationName,
        mixed $transformedValue,
        ColumnMapping $columnMapping,
        ModelMapping $mapping
    ): void {
        if (! $this->isArrayRelation($mapping->modelClass, $relationName) || ! is_array($transformedValue)) {
            return;
        }

        $relations[$relationName]['data']['__array_data'] = $transformedValue;
        $relations[$relationName]['data']['__full_array'] = true;
        
        // Configure lookup if present in column mapping
        if ($columnMapping->relationLookup !== null) {
            $this->relationLookupService->configureLookup(
                $relations[$relationName],
                $columnMapping,
                $relationName,
                '*',
                $mapping
            );
        }
    }

    /**
     * Handle pivot mapping: relation.pivot.field
     */
    private function handlePivotMapping(
        array &$relations,
        string $relationName,
        string $pivotField,
        mixed $transformedValue
    ): void {
        [$field, $isOptional] = $this->parseOptionalField($pivotField);

        if ($transformedValue !== null || ! $isOptional) {
            $relations[$relationName]['pivot'][$field] = $transformedValue;
        }
    }

    /**
     * Handle normal relation field: relation.field
     */
    private function handleNormalRelationField(
        array &$relations,
        string $relationName,
        string $relationAttribute,
        mixed $transformedValue,
        ColumnMapping $columnMapping,
        ModelMapping $mapping
    ): void {
        [$field, $isOptional] = $this->parseOptionalField($relationAttribute);

        $this->relationLookupService->configureLookup($relations[$relationName], $columnMapping, $relationName, $field, $mapping);

        if ($transformedValue === null && $isOptional) {
            return;
        }

        if ($this->isArrayRelation($mapping->modelClass, $relationName) && is_array($transformedValue)) {
            $this->addArrayRelationValue($relations, $relationName, $field, $transformedValue);
        } else {
            $this->addNormalRelationValue($relations, $relationName, $field, $transformedValue, $columnMapping);
        }
    }

    /**
     * Add value to array relation (HasMany/BelongsToMany with array source).
     */
    private function addArrayRelationValue(
        array &$relations,
        string $relationName,
        string $field,
        array $transformedValue
    ): void {
        if (! isset($relations[$relationName]['data']['__array_data'])) {
            $relations[$relationName]['data']['__array_data'] = [];
            $relations[$relationName]['data']['__fields'] = [];
        }

        if (empty($relations[$relationName]['data']['__array_data'])) {
            $relations[$relationName]['data']['__array_data'] = $transformedValue;
        }

        if (! in_array($field, $relations[$relationName]['data']['__fields'], true)) {
            $relations[$relationName]['data']['__fields'][] = $field;
        }
    }

    /**
     * Add normal (single) value to relation.
     */
    private function addNormalRelationValue(
        array &$relations,
        string $relationName,
        string $field,
        mixed $transformedValue,
        ColumnMapping $columnMapping
    ): void {
        $relations[$relationName]['data'][$field] = $transformedValue;

        if (! empty($columnMapping->transforms)) {
            $relations[$relationName]['field_transforms'][$field] = $columnMapping->transforms;
        }
    }

    /**
     * Check if relation is array type (HasMany or BelongsToMany).
     */
    private function isArrayRelation(string $modelClass, string $relationName): bool
    {
        $relationType = $this->relationTypeService->getRelationType($modelClass, $relationName);

        return in_array($relationType, [EloquentRelationType::HasMany, EloquentRelationType::BelongsToMany], true);
    }

    /**
     * Parse optional field (removes '?' prefix if present).
     *
     * @return array{0: string, 1: bool} [field_name, is_optional]
     */
    private function parseOptionalField(string $field): array
    {
        $isOptional = str_starts_with($field, '?');

        return [$isOptional ? substr($field, 1) : $field, $isOptional];
    }

    /**
     * Split a target path into parts.
     *
     * Business logic: splits path by dot separator.
     *
     * @param  string  $targetPath  The target path (e.g., "address.street")
     * @return array<string> The path parts
     */
    private function splitTargetPathIntoParts(string $targetPath): array
    {
        return explode('.', $targetPath);
    }
}
