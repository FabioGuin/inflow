<?php

namespace InFlow\Mappings;

use Illuminate\Database\Eloquent\Model;
use InFlow\Services\File\ModelSelectionService;
use InFlow\ValueObjects\ColumnMetadata;
use InFlow\ValueObjects\SourceSchema;

/**
 * Encapsulates the auto-mapping suggestion heuristics.
 *
 * This is extracted from `MappingBuilder` to keep the builder focused on
 * producing a `MappingDefinition` while this class focuses on *suggestions*.
 */
class MappingSuggestionEngine
{
    public function __construct(
        private readonly ModelSelectionService $modelSelectionService
    ) {}

    /**
     * Suggest mappings for all columns in the source schema.
     *
     * @return array<string, array{path: string, confidence: float, alternatives: array<string>, is_relation: bool}>
     */
    public function suggestMappings(SourceSchema $schema, string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        $model = new $modelClass;
        $fillable = $model->getFillable();
        $relations = $this->modelSelectionService->getModelRelations($modelClass);

        $suggestions = [];
        $fillableByNormalized = [];

        foreach ($fillable as $attribute) {
            $fillableByNormalized[$this->normalizeColumnName($attribute)] = $attribute;
        }

        $relationFillableCache = [];

        foreach ($schema->getColumnNames() as $sourceColumn) {
            $columnMeta = $schema->getColumn($sourceColumn);
            if ($columnMeta === null) {
                continue;
            }

            $normalized = $this->normalizeColumnName($sourceColumn);

            // 1) Exact match on direct attribute
            if (isset($fillableByNormalized[$normalized])) {
                $attribute = $fillableByNormalized[$normalized];
                $suggestions[$sourceColumn] = [
                    'path' => $attribute,
                    'confidence' => 1.0,
                    'alternatives' => [],
                    'is_relation' => false,
                ];

                continue;
            }

            // 2) Partial match on direct attribute
            $partialDirect = $this->findPartialDirectMatch($fillable, $normalized);
            if ($partialDirect !== null) {
                $suggestions[$sourceColumn] = [
                    'path' => $partialDirect,
                    'confidence' => 0.8,
                    'alternatives' => [],
                    'is_relation' => false,
                ];

                continue;
            }

            // 3) Exact match on relation name (only when values look like arrays/objects)
            $exactRelationMatch = $this->findExactRelationMatch($relations, $sourceColumn);
            if ($exactRelationMatch !== null && $this->isArrayColumn($columnMeta)) {
                $suggestions[$sourceColumn] = [
                    'path' => $exactRelationMatch,
                    'confidence' => 0.9,
                    'alternatives' => [],
                    'is_relation' => true,
                    'is_array_relation' => true, // Source is array, map entire array to relation
                ];

                continue;
            }

            // 3b) Partial match on relation name with array values (e.g., "tags.tag" -> "tags")
            if ($this->isArrayColumn($columnMeta)) {
                $partialRelationMatch = $this->findPartialRelationMatchForArray($relations, $sourceColumn);
                if ($partialRelationMatch !== null) {
                    $suggestions[$sourceColumn] = [
                        'path' => $partialRelationMatch,
                        'confidence' => 0.85,
                        'alternatives' => [],
                        'is_relation' => true,
                        'is_array_relation' => true, // Source is array, map entire array to relation
                    ];

                    continue;
                }
            }

            // 4) Partial match on relation name (e.g., "tag_names" -> "tags", "tag_order" -> "tags.pivot.order")
            $partialRelationMatch = $this->findPartialRelationMatch($relations, $normalized, $sourceColumn, $columnMeta);
            if ($partialRelationMatch !== null) {
                $suggestions[$sourceColumn] = $partialRelationMatch;

                continue;
            }

            // 5) Match on relation attribute (e.g. "category_name" -> "category.name")
            $relationSuggestion = $this->findRelationAttributeSuggestion(
                $relations,
                $normalized,
                $relationFillableCache
            );

            if ($relationSuggestion !== null) {
                $suggestions[$sourceColumn] = $relationSuggestion;

                continue;
            }

            // 6) Fallback: suggest first fillable (if any)
            if (! empty($fillable)) {
                $suggestions[$sourceColumn] = [
                    'path' => $fillable[0],
                    'confidence' => 0.3,
                    'alternatives' => [],
                    'is_relation' => false,
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Generate all available alternatives for a column, excluding already mapped paths.
     *
     * @param  array<string>  $mappedPaths
     * @return array<string>
     */
    public function generateAlternativesForColumn(string $suggestedPath, string $modelClass, array $mappedPaths): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        $model = new $modelClass;
        $fillable = $model->getFillable();
        $relations = $this->modelSelectionService->getModelRelations($modelClass);

        $alternatives = [];

        foreach ($fillable as $attribute) {
            if ($attribute !== $suggestedPath && ! in_array($attribute, $mappedPaths, true)) {
                $alternatives[] = $attribute;
            }
        }

        foreach ($relations as $relationName => $relationClass) {
            $relationFillable = $this->getFillableAttributesFromClass($relationClass);
            foreach ($relationFillable as $relationAttribute) {
                $relationPath = "{$relationName}.{$relationAttribute}";
                if ($relationPath !== $suggestedPath && ! in_array($relationPath, $mappedPaths, true)) {
                    $alternatives[] = $relationPath;
                }
            }
        }

        return array_values(array_unique($alternatives));
    }

    /**
     * Normalize column name for matching (lowercase, remove spaces, underscores, hyphens).
     */
    private function normalizeColumnName(string $name): string
    {
        return strtolower(str_replace([' ', '_', '-'], '', $name));
    }

    private function getFillableAttributesFromClass(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        /** @var Model $model */
        $model = new $modelClass;

        return $model->getFillable();
    }

    /**
     * @param  array<string, string>  $relations
     */
    private function findExactRelationMatch(array $relations, string $sourceColumn): ?string
    {
        $normalizedSource = $this->normalizeColumnName($sourceColumn);

        foreach ($relations as $relationName => $relationClass) {
            if ($normalizedSource === $this->normalizeColumnName($relationName)) {
                return $relationName;
            }
        }

        return null;
    }

    /**
     * Find partial match on relation name.
     *
     * Handles cases like:
     * - "tag_names" → "tags.name" (prefix match + common suffix pattern)
     * - "tag_order" → "tags.pivot.order" (prefix match + pivot field)
     * - Comma-separated values → BelongsToMany relation
     *
     * @param  array<string, string>  $relations
     * @return array{path: string, confidence: float, alternatives: array<string>, is_relation: bool}|null
     */
    private function findPartialRelationMatch(
        array $relations,
        string $normalizedSource,
        string $sourceColumn,
        ?ColumnMetadata $columnMeta
    ): ?array {
        // Common suffixes that indicate the field type
        $nameSuffixes = ['name', 'names', 'title', 'titles', 'label', 'labels'];
        $orderSuffixes = ['order', 'sort', 'position', 'rank', 'priority'];

        foreach ($relations as $relationName => $relationClass) {
            $normalizedRelation = $this->normalizeColumnName($relationName);
            $singularRelation = rtrim($normalizedRelation, 's'); // Simple singularization

            // Check if source column starts with relation name or singular form
            $matchesRelation = str_starts_with($normalizedSource, $normalizedRelation)
                || str_starts_with($normalizedSource, $singularRelation);

            if (! $matchesRelation) {
                continue;
            }

            // Extract suffix after relation prefix
            $suffix = '';
            if (str_starts_with($normalizedSource, $normalizedRelation)) {
                $suffix = substr($normalizedSource, strlen($normalizedRelation));
            } elseif (str_starts_with($normalizedSource, $singularRelation)) {
                $suffix = substr($normalizedSource, strlen($singularRelation));
            }
            $suffix = ltrim($suffix, '_');

            // Check for name-like suffixes → relation.{actual_field}
            if (in_array($suffix, $nameSuffixes, true) || $this->isCommaSeparatedValue($columnMeta)) {
                // Find actual field on the related model that matches the suffix
                $actualField = $this->findMatchingFieldOnRelatedModel($relationClass, $suffix, $nameSuffixes);

                return [
                    'path' => "{$relationName}.{$actualField}",
                    'confidence' => 0.85,
                    'alternatives' => [],
                    'is_relation' => true,
                ];
            }

            // Check for order-like suffixes → relation.pivot.order (for BelongsToMany)
            if (in_array($suffix, $orderSuffixes, true)) {
                // Check if this is a BelongsToMany relation with pivot
                if ($this->hasPivotField($relationClass, $suffix)) {
                    return [
                        'path' => "{$relationName}.pivot.{$suffix}",
                        'confidence' => 0.8,
                        'alternatives' => [],
                        'is_relation' => true,
                    ];
                }
            }

            // Check if the suffix matches an actual field on the related model
            // e.g., "book_published_at" → suffix "published_at" → check if Book has "published_at"
            if ($suffix !== '') {
                $relatedFillable = $this->getFillableAttributesFromClass($relationClass);

                // Exact match with fillable field
                if (in_array($suffix, $relatedFillable, true)) {
                    return [
                        'path' => "{$relationName}.{$suffix}",
                        'confidence' => 0.85,
                        'alternatives' => [],
                        'is_relation' => true,
                    ];
                }

                // Partial match (e.g., "publishedat" normalized matches "published_at")
                foreach ($relatedFillable as $field) {
                    if ($this->normalizeColumnName($field) === $suffix) {
                        return [
                            'path' => "{$relationName}.{$field}",
                            'confidence' => 0.8,
                            'alternatives' => [],
                            'is_relation' => true,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if column contains comma-separated values (indicator of many-to-many).
     */
    private function isCommaSeparatedValue(?ColumnMetadata $columnMeta): bool
    {
        if ($columnMeta === null || empty($columnMeta->examples)) {
            return false;
        }

        foreach ($columnMeta->examples as $example) {
            if (! is_string($example)) {
                continue;
            }

            // Check for comma-separated pattern with multiple items
            if (str_contains($example, ',') && substr_count($example, ',') >= 1) {
                $parts = explode(',', $example);
                // Verify parts are simple strings (not JSON)
                $simpleStrings = array_filter($parts, fn ($part) => ! str_starts_with(trim($part), '{'));
                if (count($simpleStrings) === count($parts)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a relation class has a specific pivot field.
     */
    private function hasPivotField(string $relationClass, string $fieldName): bool
    {
        // For now, assume common pivot fields exist
        // A more robust implementation would introspect the relation
        $commonPivotFields = ['order', 'sort', 'position', 'rank', 'priority', 'quantity', 'price'];

        return in_array($fieldName, $commonPivotFields, true);
    }

    /**
     * Find a matching field on the related model.
     *
     * If the exact suffix exists on the model, use it.
     * Otherwise, look for common name-like fields (name, title, label).
     *
     * @param  array<string>  $nameSuffixes  Common name-like suffixes to check
     */
    private function findMatchingFieldOnRelatedModel(string $relationClass, string $suffix, array $nameSuffixes): string
    {
        // Get fillable attributes from the related model
        $fillable = $this->getFillableAttributesFromClass($relationClass);

        // 1) Check if the exact suffix exists on the model
        if (in_array($suffix, $fillable, true)) {
            return $suffix;
        }

        // 2) Check for common name-like fields in order of preference
        $preferredFields = ['name', 'title', 'label', 'slug', 'code'];
        foreach ($preferredFields as $field) {
            if (in_array($field, $fillable, true)) {
                return $field;
            }
        }

        // 3) Fallback: first string-like fillable field
        foreach ($fillable as $field) {
            if (! str_ends_with($field, '_id') && ! str_ends_with($field, '_at')) {
                return $field;
            }
        }

        // 4) Last resort: use the suffix as-is
        return $suffix;
    }

    /**
     * @param  array<int, string>  $fillable
     */
    private function findPartialDirectMatch(array $fillable, string $normalizedSource): ?string
    {
        foreach ($fillable as $attribute) {
            $normalizedAttr = $this->normalizeColumnName($attribute);
            if (str_contains($normalizedSource, $normalizedAttr) || str_contains($normalizedAttr, $normalizedSource)) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $relations
     * @param  array<string, array<int, string>>  $relationFillableCache
     * @return array{path: string, confidence: float, alternatives: array<string>, is_relation: bool}|null
     */
    private function findRelationAttributeSuggestion(array $relations, string $normalizedSource, array &$relationFillableCache): ?array
    {
        foreach ($relations as $relationName => $relationClass) {
            $normalizedRelation = $this->normalizeColumnName($relationName);
            if (! str_contains($normalizedSource, $normalizedRelation)) {
                continue;
            }

            if (! array_key_exists($relationClass, $relationFillableCache)) {
                $relationFillableCache[$relationClass] = $this->getFillableAttributesFromClass($relationClass);
            }

            $relationFillable = $relationFillableCache[$relationClass];

            foreach ($relationFillable as $relationAttribute) {
                $normalizedRelationAttribute = $this->normalizeColumnName($relationAttribute);
                if (str_contains($normalizedSource, $normalizedRelationAttribute) || str_contains($normalizedSource, $relationAttribute)) {
                    return [
                        'path' => "{$relationName}.{$relationAttribute}",
                        'confidence' => 0.7,
                        'alternatives' => [],
                        'is_relation' => true,
                    ];
                }
            }

            // Relation name matches but no attribute match: suggest a "maybe" path.
            // TODO: Use findMatchingFieldOnRelatedModel instead of hardcoded 'id' fallback
            $relationAttribute = ! empty($relationFillable) ? $relationFillable[0] : 'id';

            return [
                'path' => "{$relationName}.?{$relationAttribute}",
                'confidence' => 0.5,
                'alternatives' => [],
                'is_relation' => true,
            ];
        }

        return null;
    }

    private function isArrayColumn(?ColumnMetadata $columnMeta): bool
    {
        if ($columnMeta === null || empty($columnMeta->examples)) {
            return false;
        }

        foreach ($columnMeta->examples as $example) {
            // Check if example is already an array (from JSON parsing)
            if (is_array($example)) {
                return true;
            }

            // Check if example is a string that looks like JSON array/object
            if (! is_string($example)) {
                continue;
            }

            $trimmed = trim($example);
            if (! (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{'))) {
                continue;
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find partial relation match for array columns (e.g., "tags.tag" -> "tags").
     *
     * @param  array<string, string>  $relations
     */
    private function findPartialRelationMatchForArray(array $relations, string $sourceColumn): ?string
    {
        $normalizedSource = $this->normalizeColumnName($sourceColumn);

        // Check if source column contains a relation name (e.g., "tags.tag" contains "tags")
        foreach ($relations as $relationName => $relationClass) {
            $normalizedRelation = $this->normalizeColumnName($relationName);
            
            // Check if relation name is a prefix of source column (e.g., "tags" is prefix of "tags.tag")
            if (str_starts_with($normalizedSource, $normalizedRelation.'.') || 
                str_starts_with($normalizedSource, $normalizedRelation.'_')) {
                return $relationName;
            }
        }

        return null;
    }
}
