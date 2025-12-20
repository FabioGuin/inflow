<?php

namespace InFlow\Tests\Unit\Mappings;

use InFlow\Mappings\MappingValidator;
use InFlow\Tests\TestCase;
use InFlow\ValueObjects\Data\ColumnMapping;
use InFlow\ValueObjects\Data\Row;
use InFlow\ValueObjects\Mapping\ModelMapping;

class MappingValidatorTest extends TestCase
{
    private MappingValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = $this->app->make(MappingValidator::class);
    }

    public function test_it_validates_row_with_valid_data(): void
    {
        $row = new Row(['name' => 'John Doe', 'email' => 'john@example.com'], 1);

        $mapping = new ModelMapping(
            modelClass: 'App\Models\User',
            columns: [
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
            ]
        );

        $result = $this->validator->validateRow($row, $mapping);

        $this->assertTrue($result['passes']);
        $this->assertEmpty($result['errors']);
    }

    public function test_it_validates_row_with_invalid_email(): void
    {
        $row = new Row(['name' => 'John Doe', 'email' => 'invalid-email'], 1);

        $mapping = new ModelMapping(
            modelClass: 'App\Models\User',
            columns: [
                new ColumnMapping(
                    sourceColumn: 'email',
                    targetPath: 'email',
                    transforms: ['trim', 'lower'],
                    validationRule: 'required|email'
                ),
            ]
        );

        $result = $this->validator->validateRow($row, $mapping);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('email', $result['errors']);
    }

    public function test_it_validates_row_with_missing_required_field(): void
    {
        $row = new Row(['email' => 'john@example.com'], 1);

        $mapping = new ModelMapping(
            modelClass: 'App\Models\User',
            columns: [
                new ColumnMapping(
                    sourceColumn: 'name',
                    targetPath: 'name',
                    transforms: ['trim'],
                    validationRule: 'required|string'
                ),
            ]
        );

        $result = $this->validator->validateRow($row, $mapping);

        $this->assertFalse($result['passes']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function test_it_applies_default_value_when_field_is_empty(): void
    {
        $row = new Row(['name' => '', 'email' => 'john@example.com'], 1);

        $mapping = new ModelMapping(
            modelClass: 'App\Models\User',
            columns: [
                new ColumnMapping(
                    sourceColumn: 'name',
                    targetPath: 'name',
                    transforms: ['trim'],
                    default: 'Unknown',
                    validationRule: 'required|string'
                ),
            ]
        );

        $result = $this->validator->validateRow($row, $mapping);

        // With default value, validation should pass
        $this->assertTrue($result['passes']);
    }

    public function test_it_applies_transforms_before_validation(): void
    {
        $row = new Row(['email' => '  JOHN@EXAMPLE.COM  '], 1);

        $mapping = new ModelMapping(
            modelClass: 'App\Models\User',
            columns: [
                new ColumnMapping(
                    sourceColumn: 'email',
                    targetPath: 'email',
                    transforms: ['trim', 'lower'],
                    validationRule: 'required|email'
                ),
            ]
        );

        $result = $this->validator->validateRow($row, $mapping);

        // Transforms should be applied (trim + lower), so validation should pass
        $this->assertTrue($result['passes']);
    }
}
