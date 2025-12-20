<?php

namespace InFlow\Tests\Unit\ValueObjects;

use InFlow\Enums\ColumnType;
use InFlow\Tests\TestCase;
use InFlow\ValueObjects\Data\ColumnMapping;
use InFlow\ValueObjects\Data\ColumnMetadata;
use InFlow\ValueObjects\Data\SourceSchema;
use InFlow\ValueObjects\Mapping\MappingDefinition;
use InFlow\ValueObjects\Mapping\ModelMapping;

class MappingDefinitionTest extends TestCase
{
    public function test_it_can_be_created(): void
    {
        $columns = [
            'name' => new ColumnMetadata(
                name: 'name',
                type: ColumnType::String,
                nullCount: 0,
                uniqueCount: 10,
                min: null,
                max: null,
                examples: ['John']
            ),
        ];

        $schema = new SourceSchema(columns: $columns, totalRows: 10);

        $mapping = new MappingDefinition(
            mappings: [
                new ModelMapping(
                    modelClass: 'App\Models\User',
                    columns: [
                        new ColumnMapping('name', 'name', ['trim']),
                    ]
                ),
            ],
            sourceSchema: $schema,
            name: 'Test Mapping',
            description: 'Test description'
        );

        $this->assertEquals('Test Mapping', $mapping->name);
        $this->assertEquals('Test description', $mapping->description);
        $this->assertCount(1, $mapping->mappings);
        $this->assertInstanceOf(SourceSchema::class, $mapping->sourceSchema);
    }

    public function test_it_can_convert_to_array(): void
    {
        $columns = [
            'name' => new ColumnMetadata(
                name: 'name',
                type: ColumnType::String,
                nullCount: 0,
                uniqueCount: 10,
                min: null,
                max: null,
                examples: ['John']
            ),
        ];

        $schema = new SourceSchema(columns: $columns, totalRows: 10);

        $mapping = new MappingDefinition(
            mappings: [
                new ModelMapping(
                    modelClass: 'App\Models\User',
                    columns: [
                        new ColumnMapping('name', 'name', ['trim']),
                    ]
                ),
            ],
            sourceSchema: $schema,
            name: 'Test Mapping'
        );

        $array = $mapping->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('Test Mapping', $array['name']);
        $this->assertArrayHasKey('mappings', $array);
        $this->assertArrayHasKey('source_schema', $array);
    }

    public function test_it_can_be_created_from_array(): void
    {
        $data = [
            'name' => 'Test Mapping',
            'description' => 'Test description',
            'mappings' => [
                [
                    'model' => 'App\Models\User',
                    'columns' => [
                        [
                            'source' => 'name',
                            'target' => 'name',
                            'transforms' => ['trim'],
                            'default' => null,
                            'validation_rule' => 'required|string',
                        ],
                    ],
                    'options' => [],
                ],
            ],
            'source_schema' => [
                'columns' => [
                    'name' => [
                        'name' => 'name',
                        'type' => 'string',
                        'null_count' => 0,
                        'unique_count' => 10,
                        'min' => null,
                        'max' => null,
                        'examples' => ['John'],
                    ],
                ],
                'total_rows' => 10,
            ],
        ];

        $mapping = MappingDefinition::fromArray($data);

        $this->assertInstanceOf(MappingDefinition::class, $mapping);
        $this->assertEquals('Test Mapping', $mapping->name);
        $this->assertCount(1, $mapping->mappings);
        $this->assertEquals('App\Models\User', $mapping->mappings[0]->modelClass);
    }

    public function test_it_supports_multiple_model_mappings(): void
    {
        $columns = [
            'name' => new ColumnMetadata(
                name: 'name',
                type: ColumnType::String,
                nullCount: 0,
                uniqueCount: 10,
                min: null,
                max: null,
                examples: ['John']
            ),
        ];

        $schema = new SourceSchema(columns: $columns, totalRows: 10);

        $mapping = new MappingDefinition(
            mappings: [
                new ModelMapping(
                    modelClass: 'App\Models\User',
                    columns: [new ColumnMapping('name', 'name', ['trim'])]
                ),
                new ModelMapping(
                    modelClass: 'App\Models\Profile',
                    columns: [new ColumnMapping('name', 'full_name', ['trim'])]
                ),
            ],
            sourceSchema: $schema
        );

        $this->assertCount(2, $mapping->mappings);
        $this->assertEquals('App\Models\User', $mapping->mappings[0]->modelClass);
        $this->assertEquals('App\Models\Profile', $mapping->mappings[1]->modelClass);
    }
}
