<?php

namespace InFlow\Tests\Unit\ValueObjects;

use InFlow\ValueObjects\Data\ColumnMapping;
use PHPUnit\Framework\TestCase;

class ColumnMappingTest extends TestCase
{
    public function test_it_can_be_created(): void
    {
        $mapping = new ColumnMapping(
            sourceColumn: 'Email',
            targetPath: 'email',
            transforms: ['trim', 'lower'],
            default: '',
            validationRule: 'required|email'
        );

        $this->assertEquals('Email', $mapping->sourceColumn);
        $this->assertEquals('email', $mapping->targetPath);
        $this->assertEquals(['trim', 'lower'], $mapping->transforms);
        $this->assertEquals('', $mapping->default);
        $this->assertEquals('required|email', $mapping->validationRule);
    }

    public function test_it_can_convert_to_array(): void
    {
        $mapping = new ColumnMapping(
            sourceColumn: 'Email',
            targetPath: 'email',
            transforms: ['trim', 'lower']
        );

        $array = $mapping->toArray();

        $this->assertEquals('Email', $array['source']);
        $this->assertEquals('email', $array['target']);
        $this->assertEquals(['trim', 'lower'], $array['transforms']);
    }

    public function test_it_can_be_created_from_array(): void
    {
        $data = [
            'source' => 'Email',
            'target' => 'email',
            'transforms' => ['trim', 'lower'],
            'default' => '',
            'validation_rule' => 'required|email',
        ];

        $mapping = ColumnMapping::fromArray($data);

        $this->assertEquals('Email', $mapping->sourceColumn);
        $this->assertEquals('email', $mapping->targetPath);
    }

    public function test_it_detects_nested_paths(): void
    {
        $direct = new ColumnMapping('Email', 'email');
        $nested = new ColumnMapping('Via', 'address.street');

        $this->assertFalse($direct->isNested());
        $this->assertTrue($nested->isNested());
    }

    public function test_it_parses_paths_correctly(): void
    {
        $mapping = new ColumnMapping('Via', 'address.street');

        $parts = $mapping->parsePath();

        $this->assertEquals(['address', 'street'], $parts);
    }

    public function test_it_detects_optional_parts(): void
    {
        $required = new ColumnMapping('Via', 'address.street');
        $optional = new ColumnMapping('Città', 'address.?city');

        $this->assertFalse($required->hasOptionalParts());
        $this->assertTrue($optional->hasOptionalParts());
    }
}
