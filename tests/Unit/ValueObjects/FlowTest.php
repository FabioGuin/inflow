<?php

namespace InFlow\Tests\Unit\ValueObjects;

use InFlow\Tests\TestCase;
use InFlow\ValueObjects\Flow;
use InFlow\ValueObjects\MappingDefinition;
use InFlow\ValueObjects\ModelMapping;

class FlowTest extends TestCase
{
    public function test_it_can_be_created(): void
    {
        $flow = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv', 'type' => 'file'],
            sanitizerConfig: ['remove_bom' => true],
            formatConfig: null,
            mapping: null,
            options: ['chunk_size' => 1000],
            name: 'Test Flow'
        );

        $this->assertEquals('Test Flow', $flow->name);
        $this->assertEquals(['path' => '/tmp/test.csv', 'type' => 'file'], $flow->sourceConfig);
        $this->assertEquals(1000, $flow->getChunkSize());
    }

    public function test_it_has_default_chunk_size(): void
    {
        $flow = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv'],
            sanitizerConfig: [],
            formatConfig: null,
            mapping: null,
            options: [],
            name: 'Test Flow'
        );

        $this->assertEquals(1000, $flow->getChunkSize());
    }

    public function test_it_has_default_error_policy(): void
    {
        $flow = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv'],
            sanitizerConfig: [],
            formatConfig: null,
            mapping: null,
            options: [],
            name: 'Test Flow'
        );

        $this->assertEquals('continue', $flow->getErrorPolicy());
        $this->assertFalse($flow->shouldStopOnError());
    }

    public function test_it_can_have_stop_on_error_policy(): void
    {
        $flow = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv'],
            sanitizerConfig: [],
            formatConfig: null,
            mapping: null,
            options: ['error_policy' => 'stop'],
            name: 'Test Flow'
        );

        $this->assertEquals('stop', $flow->getErrorPolicy());
        $this->assertTrue($flow->shouldStopOnError());
    }

    public function test_it_can_convert_to_array(): void
    {
        $mapping = new MappingDefinition(
            mappings: [
                new ModelMapping(
                    modelClass: 'App\Models\User',
                    columns: []
                ),
            ],
            name: 'Test Mapping'
        );

        $flow = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv'],
            sanitizerConfig: ['remove_bom' => true],
            formatConfig: ['type' => 'csv'],
            mapping: $mapping,
            options: ['chunk_size' => 500],
            name: 'Test Flow',
            description: 'Test Description'
        );

        $array = $flow->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('Test Flow', $array['name']);
        $this->assertEquals('Test Description', $array['description']);
        $this->assertArrayHasKey('source_config', $array);
        $this->assertArrayHasKey('sanitizer_config', $array);
        $this->assertArrayHasKey('format_config', $array);
        $this->assertArrayHasKey('mapping', $array);
        $this->assertArrayHasKey('options', $array);
    }

    public function test_it_can_be_created_from_array(): void
    {
        $data = [
            'name' => 'Test Flow',
            'description' => 'Test Description',
            'source_config' => ['path' => '/tmp/test.csv'],
            'sanitizer_config' => ['remove_bom' => true],
            'format_config' => null,
            'mapping' => null,
            'options' => ['chunk_size' => 500],
        ];

        $flow = Flow::fromArray($data);

        $this->assertEquals('Test Flow', $flow->name);
        $this->assertEquals('Test Description', $flow->description);
        $this->assertEquals(500, $flow->getChunkSize());
    }

    public function test_it_validates_required_fields(): void
    {
        $flow = new Flow(
            sourceConfig: [],
            sanitizerConfig: [],
            formatConfig: null,
            mapping: null,
            options: [],
            name: ''
        );

        $errors = $flow->validate();

        $this->assertContains('Flow name is required', $errors);
        $this->assertContains('Source configuration is required', $errors);
        $this->assertContains('Sanitizer configuration is required', $errors);
    }

    public function test_it_validates_chunk_size(): void
    {
        $flow = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv'],
            sanitizerConfig: ['remove_bom' => true],
            formatConfig: null,
            mapping: null,
            options: ['chunk_size' => 0],
            name: 'Test Flow'
        );

        $errors = $flow->validate();
        $this->assertContains('Chunk size must be between 1 and 100000', $errors);

        $flow2 = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv'],
            sanitizerConfig: ['remove_bom' => true],
            formatConfig: null,
            mapping: null,
            options: ['chunk_size' => 200000],
            name: 'Test Flow'
        );

        $errors2 = $flow2->validate();
        $this->assertContains('Chunk size must be between 1 and 100000', $errors2);
    }

    public function test_it_validates_error_policy(): void
    {
        $flow = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv'],
            sanitizerConfig: ['remove_bom' => true],
            formatConfig: null,
            mapping: null,
            options: ['error_policy' => 'invalid'],
            name: 'Test Flow'
        );

        $errors = $flow->validate();
        $this->assertContains('Error policy must be either "stop" or "continue"', $errors);
    }

    public function test_it_passes_validation_with_valid_config(): void
    {
        $flow = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv'],
            sanitizerConfig: ['remove_bom' => true],
            formatConfig: null,
            mapping: null,
            options: ['chunk_size' => 1000, 'error_policy' => 'continue'],
            name: 'Test Flow'
        );

        $errors = $flow->validate();
        $this->assertEmpty($errors);
    }
}
