<?php

namespace InFlow\Commands\Pipes;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InFlow\Commands\InFlowCommandContext;
use InFlow\Constants\DisplayConstants;
use InFlow\Contracts\ProcessingPipeInterface;
use InFlow\Services\Mapping\ModelCastService;
use InFlow\ValueObjects\ProcessingContext;

use function Laravel\Prompts\select;

/**
 * Dry-run preview before execution.
 *
 * Shows a complete summary of what will be imported and asks for confirmation.
 */
readonly class PreExecutionReviewPipe implements ProcessingPipeInterface
{
    public function __construct(
        private InFlowCommandContext $command,
        private ModelCastService $modelCastService
    ) {}

    public function handle(ProcessingContext $context, Closure $next): ProcessingContext
    {
        if ($context->cancelled) {
            return $next($context);
        }

        // Skip review in quiet or non-interactive mode
        if ($this->command->isQuiet() || $this->command->option('no-interaction')) {
            return $next($context);
        }

        $this->displayReviewSummary($context);

        $action = $this->promptForAction();

        return match ($action) {
            'execute' => $next($context),
            'cancel' => $next($context->withCancelled()),
            default => $next($context),
        };
    }

    private function displayReviewSummary(ProcessingContext $context): void
    {
        $this->command->newLine();
        $this->command->line('<fg=cyan;options=bold>═══════════════════════════════════════════════════════════════</>');
        $this->command->line('<fg=cyan;options=bold>  PRE-EXECUTION REVIEW</>');
        $this->command->line('<fg=cyan;options=bold>═══════════════════════════════════════════════════════════════</>');
        $this->command->newLine();

        // File info
        $this->command->line('<fg=yellow>  Source File:</>');
        $this->command->line('    Path: <fg=white>'.$context->filePath.'</>');
        if ($context->format !== null) {
            $this->command->line('    Format: <fg=white>'.$context->format->type->value.'</>');
            if ($context->format->delimiter !== null) {
                $this->command->line('    Delimiter: <fg=white>"'.$context->format->delimiter.'"</>');
            }
            if ($context->format->encoding !== null) {
                $this->command->line('    Encoding: <fg=white>'.$context->format->encoding.'</>');
            }
        }
        $this->command->newLine();

        // Data summary
        if ($context->sourceSchema !== null) {
            $columnCount = count($context->sourceSchema->getColumnNames());
            $totalRows = $context->sourceSchema->totalRows;

            $this->command->line('<fg=yellow>  Data Summary:</>');
            $this->command->line('    Columns detected: <fg=white>'.$columnCount.'</>');
            $this->command->line('    Rows to process: <fg=white>'.number_format($totalRows).'</>');
            $this->command->newLine();
        }

        // Mapping summary
        if ($context->mappingDefinition !== null) {
            $this->displayMappingSummary($context);
        }

        // Data quality warnings (required fields with null values)
        $this->displayDataQualityWarnings($context);

        // Date parsing warnings (values that won't parse correctly)
        $this->displayDateParsingWarnings($context);

        // Transform-cast conflict warnings
        $this->displayTransformCastWarnings($context);

        // Sanitization status
        $this->command->line('<fg=yellow>  Options:</>');
        $sanitizeStatus = ($context->shouldSanitize ?? false) ? '<fg=green>enabled</>' : '<fg=gray>disabled</>';
        $this->command->line('    Sanitization: '.$sanitizeStatus);

        $this->command->newLine();
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);
    }

    private function displayMappingSummary(ProcessingContext $context): void
    {
        $mapping = $context->mappingDefinition;

        $this->command->line('<fg=yellow>  Mapping Configuration:</>');

        foreach ($mapping->mappings as $modelMapping) {
            $this->command->line('    Target model: <fg=white>'.$modelMapping->modelClass.'</>');

            $directFields = [];
            $relationFields = [];
            $transforms = [];

            foreach ($modelMapping->columns as $columnMapping) {
                $targetParts = explode('.', $columnMapping->targetPath);

                if (count($targetParts) === 1) {
                    $directFields[] = $columnMapping->targetPath;
                } else {
                    $relationName = $targetParts[0];
                    if (! isset($relationFields[$relationName])) {
                        $relationFields[$relationName] = [];
                    }
                    $relationFields[$relationName][] = $targetParts[1];
                }

                if (! empty($columnMapping->transforms)) {
                    foreach ($columnMapping->transforms as $transform) {
                        $transforms[] = $transform;
                    }
                }
            }

            $this->command->line('    Direct fields: <fg=white>'.count($directFields).'</>');

            if (! empty($relationFields)) {
                $this->command->line('    Relations: <fg=white>'.count($relationFields).'</>');
                foreach ($relationFields as $relation => $fields) {
                    $this->command->line('      • '.$relation.': '.count($fields).' fields');
                }
            }

            if (! empty($transforms)) {
                $uniqueTransforms = array_unique($transforms);
                $this->command->line('    Transforms: <fg=white>'.count($uniqueTransforms).' types</>');
            }

            // Duplicate handling (from options)
            $uniqueKey = $modelMapping->options['unique_key'] ?? null;
            if ($uniqueKey !== null) {
                $uniqueKeys = is_array($uniqueKey)
                    ? implode(', ', $uniqueKey)
                    : $uniqueKey;
                $this->command->line('    Unique key: <fg=white>'.$uniqueKeys.'</>');
                $duplicateStrategy = $modelMapping->options['duplicate_strategy'] ?? 'error';
                $this->command->line('    On duplicate: <fg=white>'.$duplicateStrategy.'</>');
            }
        }

        $this->command->newLine();
    }

    private function displayDataQualityWarnings(ProcessingContext $context): void
    {
        if ($context->mappingDefinition === null || $context->sourceSchema === null) {
            return;
        }

        $warnings = [];

        foreach ($context->mappingDefinition->mappings as $modelMapping) {
            // Get required fields from model
            $requiredFields = $this->getRequiredFields($modelMapping->modelClass);
            $columnStats = $context->sourceSchema->columns;

            foreach ($modelMapping->columns as $columnMapping) {
                $targetPath = $columnMapping->targetPath;
                $sourceColumn = $columnMapping->sourceColumn;

                // Only check direct fields (not relations)
                if (! str_contains($targetPath, '.') && in_array($targetPath, $requiredFields, true)) {
                    // Check if source column has null/empty values
                    $columnMeta = $columnStats[$sourceColumn] ?? null;
                    if ($columnMeta !== null && $columnMeta->nullCount > 0) {
                        $nullPercent = round(($columnMeta->nullCount / $context->sourceSchema->totalRows) * 100, 1);
                        $warnings[] = [
                            'source' => $sourceColumn,
                            'target' => $targetPath,
                            'null_count' => $columnMeta->nullCount,
                            'null_percent' => $nullPercent,
                            'has_coalesce' => $this->hasCoalesceTransform($columnMapping->transforms),
                        ];
                    }
                }
            }
        }

        if (empty($warnings)) {
            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=yellow;options=bold>  ⚠ Data Quality Warnings:</>');
        $this->command->line('<fg=gray>  Required fields with null/empty values will fail during import.</>');
        $this->command->newLine();

        foreach ($warnings as $warning) {
            $this->command->line(
                '    <fg=red>•</> <fg=white>'.$warning['source'].'</> → <fg=cyan>'.$warning['target'].'</>: '.
                '<fg=yellow>'.$warning['null_count'].' empty ('.$warning['null_percent'].'%)</>'
            );

            if (! $warning['has_coalesce']) {
                $this->command->line(
                    '      <fg=gray>→ Tip: Add</> <fg=green>coalesce:DefaultValue</> <fg=gray>transform to set a fallback</>'
                );
            }
        }

        $this->command->newLine();
    }

    /**
     * Display warnings for date fields with unparseable values.
     *
     * Analyzes sample data to detect values that won't parse correctly as dates.
     */
    private function displayDateParsingWarnings(ProcessingContext $context): void
    {
        if ($context->mappingDefinition === null || $context->reader === null) {
            return;
        }

        $dateWarnings = [];

        // Find columns mapped to date fields with cast:date transform
        foreach ($context->mappingDefinition->mappings as $modelMapping) {
            foreach ($modelMapping->columns as $columnMapping) {
                $hasDateCast = false;
                foreach ($columnMapping->transforms as $transform) {
                    if (str_starts_with($transform, 'cast:date')) {
                        $hasDateCast = true;
                        break;
                    }
                }

                if (! $hasDateCast) {
                    continue;
                }

                // Sample values from this column
                $unparseable = $this->findUnparseableDateValues(
                    $context->reader,
                    $columnMapping->sourceColumn
                );

                if (! empty($unparseable)) {
                    $dateWarnings[] = [
                        'source' => $columnMapping->sourceColumn,
                        'target' => $columnMapping->targetPath,
                        'values' => $unparseable,
                    ];
                }
            }
        }

        if (empty($dateWarnings)) {
            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=yellow;options=bold>  ⚠ Date Parsing Warnings:</>');
        $this->command->line('<fg=gray>  These values may not parse correctly as dates.</>');
        $this->command->newLine();

        foreach ($dateWarnings as $warning) {
            $this->command->line(
                '    <fg=red>•</> <fg=white>'.$warning['source'].'</> → <fg=cyan>'.$warning['target'].'</>:'
            );

            foreach ($warning['values'] as $valueInfo) {
                $value = $valueInfo['value'];
                $issue = $valueInfo['issue'];
                $this->command->line(
                    '      <fg=gray>Row '.$valueInfo['row'].':</> "<fg=yellow>'.$value.'</>" <fg=red>('.$issue.')</>'
                );
            }

            $this->command->line(
                '      <fg=gray>→ Tip: Use</> <fg=green>parse_date:FORMAT</> <fg=gray>to specify the exact input format</>'
            );
        }

        $this->command->newLine();
    }

    /**
     * Display warnings about transform-cast conflicts.
     *
     * Laravel model casts take precedence over transforms, so if a transform
     * (e.g., round:1) conflicts with a model cast (e.g., decimal:2), the cast
     * will override the transform result.
     */
    private function displayTransformCastWarnings(ProcessingContext $context): void
    {
        if ($context->mappingDefinition === null) {
            return;
        }

        $warnings = [];

        foreach ($context->mappingDefinition->mappings as $modelMapping) {
            foreach ($modelMapping->columns as $columnMapping) {
                // Only check direct fields (not relations)
                if (str_contains($columnMapping->targetPath, '.')) {
                    continue;
                }

                // Check each transform for conflicts
                foreach ($columnMapping->transforms as $transformSpec) {
                    $conflict = $this->modelCastService->checkTransformCastConflict(
                        $transformSpec,
                        $modelMapping->modelClass,
                        $columnMapping->targetPath
                    );

                    if ($conflict['conflicts']) {
                        $warnings[] = [
                            'source' => $columnMapping->sourceColumn,
                            'target' => $columnMapping->targetPath,
                            'transform' => $transformSpec,
                            'message' => $conflict['message'],
                        ];
                    }
                }
            }
        }

        if (empty($warnings)) {
            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=yellow;options=bold>  ⚠ Transform-Cast Conflicts:</>');
        $this->command->line('<fg=gray>  Laravel model casts take precedence over transforms. The following transforms may be overridden:</>');
        $this->command->newLine();

        foreach ($warnings as $warning) {
            $this->command->line(
                "    <fg=yellow>{$warning['source']}</> → <fg=green>{$warning['target']}</>"
            );
            $this->command->line(
                "      Transform: <fg=cyan>{$warning['transform']}</>"
            );
            $this->command->line(
                "      <fg=gray>→ {$warning['message']}</>"
            );
            $this->command->newLine();
        }
    }

    /**
     * Find date values that won't parse correctly.
     *
     * @return array<array{row: int, value: string, issue: string}>
     */
    private function findUnparseableDateValues($reader, string $columnName): array
    {
        $unparseable = [];
        $reader->rewind();
        $rowNumber = 0;
        $maxSamples = 5; // Limit warning output

        foreach ($reader as $row) {
            $rowNumber++;
            $rowData = $row instanceof \InFlow\ValueObjects\Row ? $row->toArray() : $row;
            $value = $rowData[$columnName] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $issue = $this->checkDateParseability($value);
            if ($issue !== null && count($unparseable) < $maxSamples) {
                $unparseable[] = [
                    'row' => $rowNumber,
                    'value' => $value,
                    'issue' => $issue,
                ];
            }
        }

        return $unparseable;
    }

    /**
     * Check if a value will parse correctly as a date.
     *
     * Uses strtotime() as the source of truth - if it fails or produces
     * suspicious results, we report it.
     *
     * @return string|null Issue description, or null if value parses correctly
     */
    private function checkDateParseability(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return 'not a string or number';
        }

        $stringValue = trim((string) $value);

        // Year-only values (e.g., "2015") - strtotime parses these incorrectly
        if (preg_match('/^\d{4}$/', $stringValue)) {
            return 'year-only format - use parse_date:Y';
        }

        // Let strtotime be the judge
        $timestamp = strtotime($stringValue);

        if ($timestamp === false) {
            return 'format not recognized';
        }

        if ($timestamp <= 0) {
            return 'invalid result (epoch)';
        }

        return null;
    }

    /**
     * Get required (non-nullable) fields from model.
     *
     * @return array<string>
     */
    private function getRequiredFields(string $modelClass): array
    {
        try {
            if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                return [];
            }

            /** @var Model $model */
            $model = new $modelClass;
            $table = $model->getTable();
            $connection = $model->getConnection();

            // Get column info from database
            $columns = $connection->getSchemaBuilder()->getColumns($table);
            $required = [];

            foreach ($columns as $column) {
                $name = $column['name'];

                // Skip auto-generated fields
                if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    continue;
                }

                // Check if NOT NULL and no default
                if (! $column['nullable'] && $column['default'] === null) {
                    $required[] = $name;
                }
            }

            return $required;
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', ['operation' => 'getRequiredColumns', 'model' => $modelClass]);

            return [];
        }
    }

    /**
     * Check if column has coalesce/default transform.
     *
     * @param  array<string>  $transforms
     */
    private function hasCoalesceTransform(array $transforms): bool
    {
        foreach ($transforms as $transform) {
            if (str_starts_with($transform, 'coalesce:') || str_starts_with($transform, 'default:')) {
                return true;
            }
        }

        return false;
    }

    private function promptForAction(): string
    {
        return select(
            label: '  Ready to execute?',
            options: [
                'execute' => '▶ Execute import now',
                'cancel' => '✕ Cancel and exit',
            ],
            default: 'execute'
        );
    }
}
