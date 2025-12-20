<?php

namespace InFlow\Services\Formatter;

use InFlow\ValueObjects\Data\SourceSchema;
use InFlow\ViewModels\SchemaViewModel;

/**
 * Formatter for schema information
 */
readonly class SchemaFormatter
{
    public function format(SourceSchema $schema): SchemaViewModel
    {
        $columns = [];

        foreach ($schema->columns as $column) {
            $examples = [];
            if (! empty($column->examples)) {
                $examples = array_slice($column->examples, 0, 3);
            }

            $nullPercent = $schema->totalRows > 0
                ? round(($column->nullCount / $schema->totalRows) * 100, 1)
                : 0.0;

            $columns[] = [
                'name' => $column->name,
                'type' => $column->type->value,
                'nullPercent' => $nullPercent,
                'examples' => $examples,
            ];
        }

        return new SchemaViewModel(
            title: 'Data Schema',
            columns: $columns,
        );
    }
}
