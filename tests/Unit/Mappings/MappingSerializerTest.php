<?php

namespace InFlow\Tests\Unit\Mappings;

use InFlow\Enums\ColumnType;
use InFlow\Mappings\MappingSerializer;
use InFlow\Tests\TestCase;
use InFlow\ValueObjects\Data\ColumnMapping;
use InFlow\ValueObjects\Data\ColumnMetadata;
use InFlow\ValueObjects\Data\SourceSchema;
use InFlow\ValueObjects\Mapping\MappingDefinition;
use InFlow\ValueObjects\Mapping\ModelMapping;

class MappingSerializerTest extends TestCase
{
    private string $testFilesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testFilesPath = sys_get_temp_dir().'/inflow_mapping_tests_'.uniqid();
        if (! is_dir($this->testFilesPath)) {
            mkdir($this->testFilesPath, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testFilesPath)) {
            array_map('unlink', glob($this->testFilesPath.'/*'));
            rmdir($this->testFilesPath);
        }
        parent::tearDown();
    }

    private function createTestMapping(): MappingDefinition
    {
        $columns = [
            'name' => new ColumnMetadata(
                name: 'name',
                type: ColumnType::String,
                nullCount: 0,
                uniqueCount: 10,
                min: null,
                max: null,
                examples: ['John', 'Jane']
            ),
            'email' => new ColumnMetadata(
                name: 'email',
                type: ColumnType::Email,
                nullCount: 0,
                uniqueCount: 10,
                min: null,
                max: null,
                examples: ['john@example.com']
            ),
        ];

        $schema = new SourceSchema(columns: $columns, totalRows: 10);

        $columnMappings = [
            new ColumnMapping(
                sourceColumn: 'name',
                targetPath: 'name',
                transforms: ['trim'],
                validationRule: 'required|string'
            ),
            new ColumnMapping(
                sourceColumn: 'email',
                targetPath: 'email',
                transforms: ['trim', 'lower'],
                validationRule: 'required|email'
            ),
        ];

        $modelMapping = new ModelMapping(
            modelClass: 'App\Models\User',
            columns: $columnMappings
        );

        return new MappingDefinition(
            mappings: [$modelMapping],
            sourceSchema: $schema,
            name: 'Test Mapping',
            description: 'Test mapping for users'
        );
    }

    public function test_it_saves_mapping_to_json_file(): void
    {
        $mapping = $this->createTestMapping();
        $serializer = new MappingSerializer;
        $filePath = $this->testFilesPath.'/test_mapping.json';

        $serializer->saveToFile($mapping, $filePath);

        $this->assertFileExists($filePath);
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        $this->assertIsArray($data);
        $this->assertEquals('Test Mapping', $data['name']);
        $this->assertArrayHasKey('mappings', $data);
        $this->assertCount(1, $data['mappings']);
    }

    public function test_it_loads_mapping_from_json_file(): void
    {
        $mapping = $this->createTestMapping();
        $serializer = new MappingSerializer;
        $filePath = $this->testFilesPath.'/test_mapping.json';

        $serializer->saveToFile($mapping, $filePath);
        $loaded = $serializer->loadFromFile($filePath);

        $this->assertInstanceOf(MappingDefinition::class, $loaded);
        $this->assertEquals('Test Mapping', $loaded->name);
        $this->assertCount(1, $loaded->mappings);
        $this->assertEquals('App\Models\User', $loaded->mappings[0]->modelClass);
        $this->assertCount(2, $loaded->mappings[0]->columns);
    }

    public function test_it_preserves_column_mappings_on_load(): void
    {
        $mapping = $this->createTestMapping();
        $serializer = new MappingSerializer;
        $filePath = $this->testFilesPath.'/test_mapping.json';

        $serializer->saveToFile($mapping, $filePath);
        $loaded = $serializer->loadFromFile($filePath);

        $nameColumn = null;
        foreach ($loaded->mappings[0]->columns as $col) {
            if ($col->sourceColumn === 'name') {
                $nameColumn = $col;
                break;
            }
        }

        $this->assertNotNull($nameColumn);
        $this->assertEquals('name', $nameColumn->targetPath);
        $this->assertEquals(['trim'], $nameColumn->transforms);
        $this->assertEquals('required|string', $nameColumn->validationRule);
    }

    public function test_it_preserves_transforms_on_load(): void
    {
        $mapping = $this->createTestMapping();
        $serializer = new MappingSerializer;
        $filePath = $this->testFilesPath.'/test_mapping.json';

        $serializer->saveToFile($mapping, $filePath);
        $loaded = $serializer->loadFromFile($filePath);

        $emailColumn = null;
        foreach ($loaded->mappings[0]->columns as $col) {
            if ($col->sourceColumn === 'email') {
                $emailColumn = $col;
                break;
            }
        }

        $this->assertNotNull($emailColumn);
        $this->assertEquals(['trim', 'lower'], $emailColumn->transforms);
    }
}
