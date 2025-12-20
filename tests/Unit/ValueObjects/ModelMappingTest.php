<?php

namespace InFlow\Tests\Unit\ValueObjects;

use InFlow\Tests\TestCase;
use InFlow\ValueObjects\Data\ColumnMapping;
use InFlow\ValueObjects\Mapping\ModelMapping;

class ModelMappingTest extends TestCase
{
    public function test_it_can_be_created(): void
    {
        $mapping = new ModelMapping(
            modelClass: 'App\Models\User',
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
                new ColumnMapping('email', 'email', ['trim', 'lower']),
            ],
            options: ['update_on_duplicate' => true]
        );

        $this->assertEquals('App\Models\User', $mapping->modelClass);
        $this->assertCount(2, $mapping->columns);
        $this->assertEquals('update_on_duplicate', array_key_first($mapping->options));
    }

    public function test_it_can_convert_to_array(): void
    {
        $mapping = new ModelMapping(
            modelClass: 'App\Models\User',
            columns: [
                new ColumnMapping('name', 'name', ['trim']),
            ]
        );

        $array = $mapping->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('App\Models\User', $array['model']);
        $this->assertArrayHasKey('columns', $array);
        $this->assertCount(1, $array['columns']);
    }

    public function test_it_can_be_created_from_array(): void
    {
        $data = [
            'model' => 'App\Models\User',
            'columns' => [
                [
                    'source' => 'name',
                    'target' => 'name',
                    'transforms' => ['trim'],
                ],
            ],
            'options' => [],
        ];

        $mapping = ModelMapping::fromArray($data);

        $this->assertInstanceOf(ModelMapping::class, $mapping);
        $this->assertEquals('App\Models\User', $mapping->modelClass);
        $this->assertCount(1, $mapping->columns);
    }
}
