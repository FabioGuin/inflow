<?php

namespace InFlow\Mappings;

use Illuminate\Support\Facades\Validator;
use InFlow\Transforms\TransformEngine;
use InFlow\ValueObjects\Data\Row;
use InFlow\ValueObjects\Mapping\ModelMapping;

/**
 * Validator for mapping definitions using Laravel validation
 */
class MappingValidator
{
    public function __construct(
        private readonly TransformEngine $transformEngine
    ) {}

    /**
     * Validate a row against a model mapping
     *
     * @return array{passes: bool, errors: array<string, array<string>>}
     */
    public function validateRow(Row $row, ModelMapping $mapping): array
    {
        $rules = [];
        $data = [];

        foreach ($mapping->columns as $columnMapping) {
            $value = $row->get($columnMapping->sourceColumn);

            // Apply default if value is empty
            if ($value === null || $value === '') {
                $value = $columnMapping->default;
            }

            // Apply transformations
            $transformedValue = $this->transformEngine->apply(
                $value,
                $columnMapping->transforms,
                ['row' => $row->toArray()]
            );

            // Get validation rule from mapping or model
            $rule = $columnMapping->validationRule ?? $this->getModelValidationRule(
                $mapping->modelClass,
                $columnMapping->targetPath
            );

            if ($rule !== null) {
                $rules[$columnMapping->targetPath] = $rule;
                $data[$columnMapping->targetPath] = $transformedValue;
            }
        }

        // Adjust unique rules for duplicate_strategy: "update"
        $rules = $this->adjustUniqueRulesForUpdate($rules, $data, $mapping);

        // Validate using Laravel Validator
        $validator = Validator::make($data, $rules);

        return [
            'passes' => $validator->passes(),
            'errors' => $validator->errors()->toArray(),
        ];
    }

    /**
     * Validate multiple rows and return aggregated results
     *
     * @param  array<Row>  $rows
     * @return array{passed: int, failed: int, errors: array<int, array<string, array<string>>>}
     */
    public function validateRows(array $rows, ModelMapping $mapping): array
    {
        $passed = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $result = $this->validateRow($row, $mapping);

            if ($result['passes']) {
                $passed++;
            } else {
                $failed++;
                $errors[$index] = $result['errors'];
            }
        }

        return [
            'passed' => $passed,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Adjust unique validation rules when duplicate_strategy is "update"
     *
     * When duplicate_strategy is "update", we need to exclude existing records
     * from unique validation to allow updates instead of blocking them.
     *
     * @param  array<string, string>  $rules  Validation rules
     * @param  array<string, mixed>  $data  Data to validate
     * @param  ModelMapping  $mapping  The model mapping
     * @return array<string, string> Adjusted rules
     */
    private function adjustUniqueRulesForUpdate(array $rules, array $data, ModelMapping $mapping): array
    {
        $duplicateStrategy = $mapping->options['duplicate_strategy'] ?? 'error';
        $uniqueKey = $mapping->options['unique_key'] ?? null;

        // Only adjust if duplicate_strategy is "update"
        if ($duplicateStrategy !== 'update' || $uniqueKey === null) {
            return $rules;
        }

        // Check if unique_key value exists in data
        if (! isset($data[$uniqueKey]) || $data[$uniqueKey] === null) {
            return $rules;
        }

        // Find existing record by unique key
        $existingRecord = $this->findExistingRecord($mapping->modelClass, $uniqueKey, $data[$uniqueKey]);

        if ($existingRecord === null) {
            // No existing record, no need to adjust rules
            return $rules;
        }

        // Adjust unique rules to exclude the existing record
        $adjustedRules = [];
        foreach ($rules as $field => $rule) {
            if (str_contains($rule, 'unique:')) {
                $adjustedRules[$field] = $this->adjustUniqueRule($rule, $mapping->modelClass, $existingRecord->getKey());
            } else {
                $adjustedRules[$field] = $rule;
            }
        }

        return $adjustedRules;
    }

    /**
     * Adjust a single unique validation rule to exclude a specific record ID
     *
     * @param  string  $rule  The original unique rule (e.g., "unique:authors,email")
     * @param  string  $modelClass  The model class
     * @param  int|string  $excludeId  The ID to exclude from unique check
     * @return string Adjusted rule (e.g., "unique:authors,email,123")
     */
    private function adjustUniqueRule(string $rule, string $modelClass, int|string $excludeId): string
    {
        // Parse unique rule: "unique:table,column" or "unique:table,column,id,idColumn"
        if (! preg_match('/unique:([^,]+),([^,]+)(?:,([^,]+))?(?:,([^,]+))?/', $rule, $matches)) {
            return $rule;
        }

        $table = $matches[1];
        $column = $matches[2];
        $existingExcludeId = $matches[3] ?? null;
        $idColumn = $matches[4] ?? null;

        // If rule already excludes an ID, keep it (don't override)
        if ($existingExcludeId !== null) {
            return $rule;
        }

        // Get the table name from model if not provided
        if ($table === $modelClass || class_exists($table)) {
            $model = new $table;
            $table = $model->getTable();
        }

        // Build adjusted rule: "unique:table,column,{id}"
        $adjustedRule = "unique:{$table},{$column},{$excludeId}";

        // Add idColumn if specified
        if ($idColumn !== null) {
            $adjustedRule .= ",{$idColumn}";
        }

        // Replace the unique rule in the original rule string
        return preg_replace('/unique:[^|]+/', $adjustedRule, $rule);
    }

    /**
     * Find an existing record by unique key value
     *
     * @param  string  $modelClass  The model class
     * @param  string  $uniqueKey  The unique key field name
     * @param  mixed  $value  The unique key value
     */
    private function findExistingRecord(string $modelClass, string $uniqueKey, mixed $value): ?\Illuminate\Database\Eloquent\Model
    {
        if (! class_exists($modelClass)) {
            return null;
        }

        try {
            return $modelClass::where($uniqueKey, $value)->first();
        } catch (\Exception $e) {
            // If query fails, return null (e.g., table doesn't exist)
            return null;
        }
    }

    /**
     * Get validation rule from model if available
     */
    private function getModelValidationRule(string $modelClass, string $targetPath): ?string
    {
        if (! class_exists($modelClass)) {
            return null;
        }

        $model = new $modelClass;

        // Check if model has rules() method
        if (method_exists($model, 'rules')) {
            $rules = $model->rules();
            if (isset($rules[$targetPath])) {
                return $rules[$targetPath];
            }
        }

        // Check if model has validationRules() method (alternative)
        if (method_exists($model, 'validationRules')) {
            $rules = $model->validationRules();
            if (isset($rules[$targetPath])) {
                return $rules[$targetPath];
            }
        }

        return null;
    }
}
