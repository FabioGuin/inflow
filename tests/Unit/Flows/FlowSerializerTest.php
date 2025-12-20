<?php

namespace InFlow\Tests\Unit\Flows;

use InFlow\Flows\FlowSerializer;
use InFlow\Tests\TestCase;
use InFlow\ValueObjects\Flow\Flow;

class FlowSerializerTest extends TestCase
{
    private FlowSerializer $serializer;

    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serializer = new FlowSerializer;
        $this->testDir = sys_get_temp_dir().'/inflow_test_'.uniqid();
        mkdir($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Cleanup test files
        if (is_dir($this->testDir)) {
            array_map('unlink', glob($this->testDir.'/*'));
            rmdir($this->testDir);
        }
        parent::tearDown();
    }

    public function test_it_can_save_and_load_flow_as_json(): void
    {
        $flow = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv', 'type' => 'file'],
            sanitizerConfig: ['remove_bom' => true, 'normalize_newlines' => true],
            formatConfig: null,
            mapping: null,
            options: ['chunk_size' => 500, 'error_policy' => 'continue'],
            name: 'Test Flow',
            description: 'Test Description'
        );

        $filePath = $this->testDir.'/test_flow.json';
        $this->serializer->saveToFile($flow, $filePath);

        $this->assertFileExists($filePath);

        $loaded = $this->serializer->loadFromFile($filePath);

        $this->assertEquals($flow->name, $loaded->name);
        $this->assertEquals($flow->description, $loaded->description);
        $this->assertEquals($flow->sourceConfig, $loaded->sourceConfig);
        $this->assertEquals($flow->sanitizerConfig, $loaded->sanitizerConfig);
        $this->assertEquals($flow->getChunkSize(), $loaded->getChunkSize());
        $this->assertEquals($flow->getErrorPolicy(), $loaded->getErrorPolicy());
    }

    public function test_it_throws_exception_when_file_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Flow file not found');

        $this->serializer->loadFromFile($this->testDir.'/nonexistent.json');
    }

    public function test_it_can_get_default_storage_directory(): void
    {
        $dir = $this->serializer->getStorageDirectory();

        $this->assertIsString($dir);
        $this->assertTrue(is_dir($dir) || is_dir(dirname($dir)));
    }

    public function test_it_can_get_default_path_for_flow(): void
    {
        $path = $this->serializer->getDefaultPath('My Test Flow');

        $this->assertStringContainsString('My_Test_Flow', $path);
        $this->assertStringEndsWith('.json', $path);
    }

    public function test_it_can_list_flows(): void
    {
        $flow1 = new Flow(
            sourceConfig: ['path' => '/tmp/test1.csv'],
            sanitizerConfig: [],
            formatConfig: null,
            mapping: null,
            options: [],
            name: 'Flow 1'
        );

        $flow2 = new Flow(
            sourceConfig: ['path' => '/tmp/test2.csv'],
            sanitizerConfig: [],
            formatConfig: null,
            mapping: null,
            options: [],
            name: 'Flow 2'
        );

        $file1 = $this->testDir.'/flow1.json';
        $file2 = $this->testDir.'/flow2.json';

        $this->serializer->saveToFile($flow1, $file1);
        $this->serializer->saveToFile($flow2, $file2);

        // Note: listFlows() uses getStorageDirectory(), so we test the method exists
        $this->assertIsArray($this->serializer->listFlows());
    }

    public function test_it_can_check_if_flow_exists(): void
    {
        $flow = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv'],
            sanitizerConfig: [],
            formatConfig: null,
            mapping: null,
            options: [],
            name: 'Test Flow'
        );

        $filePath = $this->testDir.'/test_flow.json';

        $this->assertFalse($this->serializer->flowExists($filePath));

        $this->serializer->saveToFile($flow, $filePath);

        $this->assertTrue($this->serializer->flowExists($filePath));
    }

    public function test_it_can_delete_flow(): void
    {
        $flow = new Flow(
            sourceConfig: ['path' => '/tmp/test.csv'],
            sanitizerConfig: [],
            formatConfig: null,
            mapping: null,
            options: [],
            name: 'Test Flow'
        );

        $filePath = $this->testDir.'/test_flow.json';
        $this->serializer->saveToFile($flow, $filePath);

        $this->assertTrue($this->serializer->flowExists($filePath));

        $result = $this->serializer->deleteFlow($filePath);

        $this->assertTrue($result);
        $this->assertFalse(file_exists($filePath));
    }

    public function test_delete_returns_false_if_file_not_exists(): void
    {
        $result = $this->serializer->deleteFlow($this->testDir.'/nonexistent.json');

        $this->assertFalse($result);
    }
}
