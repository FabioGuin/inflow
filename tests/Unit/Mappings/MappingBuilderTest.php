<?php

namespace InFlow\Tests\Unit\Mappings;

use InFlow\Enums\ColumnType;
use InFlow\Mappings\MappingBuilder;
use InFlow\Tests\TestCase;
use InFlow\ValueObjects\ColumnMetadata;
use InFlow\ValueObjects\MappingDefinition;
use InFlow\ValueObjects\SourceSchema;

class MappingBuilderTest extends TestCase
{
    private function makeBuilder(): MappingBuilder
    {
        return $this->app->make(MappingBuilder::class);
    }

    private function createTestSchema(): SourceSchema
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
                examples: ['john@example.com', 'jane@example.com']
            ),
            'age' => new ColumnMetadata(
                name: 'age',
                type: ColumnType::Int,
                nullCount: 0,
                uniqueCount: 8,
                min: 18,
                max: 65,
                examples: ['25', '30', '35']
            ),
        ];

        return new SourceSchema(columns: $columns, totalRows: 10);
    }

    public function test_it_creates_mapping_with_exact_match(): void
    {
        $schema = $this->createTestSchema();
        $builder = $this->makeBuilder();

        $mapping = $builder->autoMapInteractive(
            schema: $schema,
            modelClass: TestUserModel::class
        );

        $this->assertInstanceOf(MappingDefinition::class, $mapping);
        $this->assertCount(1, $mapping->mappings);

        // Check that name column is mapped correctly
        $nameColumn = null;
        foreach ($mapping->mappings[0]->columns as $col) {
            if ($col->sourceColumn === 'name') {
                $nameColumn = $col;
                break;
            }
        }

        $this->assertNotNull($nameColumn);
        $this->assertEquals('name', $nameColumn->targetPath);
    }

    public function test_it_creates_mapping_without_callbacks(): void
    {
        $schema = $this->createTestSchema();
        $builder = $this->makeBuilder();

        $mapping = $builder->autoMapInteractive(
            schema: $schema,
            modelClass: TestUserModel::class
        );

        $this->assertInstanceOf(MappingDefinition::class, $mapping);
        $this->assertCount(1, $mapping->mappings);
        $this->assertGreaterThan(0, count($mapping->mappings[0]->columns));
    }

    public function test_it_uses_interactive_callback(): void
    {
        $schema = $this->createTestSchema();
        $builder = $this->makeBuilder();

        $confirmedColumns = [];

        $mapping = $builder->autoMapInteractive(
            schema: $schema,
            modelClass: TestUserModel::class,
            interactiveCallback: function ($sourceColumn, $suggestedPath, $confidence, $alternatives) use (&$confirmedColumns) {
                $confirmedColumns[] = $sourceColumn;

                return true; // Accept all
            }
        );

        $this->assertInstanceOf(MappingDefinition::class, $mapping);
        $this->assertGreaterThan(0, count($confirmedColumns));
    }

    public function test_it_uses_transform_callback(): void
    {
        $schema = $this->createTestSchema();
        $builder = $this->makeBuilder();

        $customTransforms = ['trim', 'lower'];

        $mapping = $builder->autoMapInteractive(
            schema: $schema,
            modelClass: TestUserModel::class,
            transformCallback: function ($sourceColumn, $targetPath, $suggestedTransforms, $columnMeta) use ($customTransforms) {
                return $customTransforms;
            }
        );

        $this->assertInstanceOf(MappingDefinition::class, $mapping);
        $emailColumn = null;
        foreach ($mapping->mappings[0]->columns as $col) {
            if ($col->sourceColumn === 'email') {
                $emailColumn = $col;
                break;
            }
        }

        $this->assertNotNull($emailColumn);
        $this->assertEquals($customTransforms, $emailColumn->transforms);
    }

    public function test_it_skips_columns_when_callback_returns_false(): void
    {
        $schema = $this->createTestSchema();
        $builder = $this->makeBuilder();

        $mapping = $builder->autoMapInteractive(
            schema: $schema,
            modelClass: TestUserModel::class,
            interactiveCallback: function ($sourceColumn, $suggestedPath, $confidence, $alternatives) {
                return false; // Skip all
            }
        );

        $this->assertInstanceOf(MappingDefinition::class, $mapping);
        $this->assertCount(0, $mapping->mappings[0]->columns);
    }

    public function test_it_uses_custom_path_from_callback(): void
    {
        $schema = $this->createTestSchema();
        $builder = $this->makeBuilder();

        $mapping = $builder->autoMapInteractive(
            schema: $schema,
            modelClass: TestUserModel::class,
            interactiveCallback: function ($sourceColumn, $suggestedPath, $confidence, $alternatives) {
                if ($sourceColumn === 'name') {
                    return 'full_name'; // Custom path
                }

                return true;
            }
        );

        $nameColumn = null;
        foreach ($mapping->mappings[0]->columns as $col) {
            if ($col->sourceColumn === 'name') {
                $nameColumn = $col;
                break;
            }
        }

        $this->assertNotNull($nameColumn);
        $this->assertEquals('full_name', $nameColumn->targetPath);
    }
}

/**
 * Simple test model for testing
 */
class TestUserModel extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['name', 'email', 'age', 'full_name'];
}
