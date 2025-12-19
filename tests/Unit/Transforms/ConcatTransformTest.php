<?php

namespace InFlow\Tests\Unit\Transforms;

use InFlow\Transforms\ConcatTransform;
use PHPUnit\Framework\TestCase;

class ConcatTransformTest extends TestCase
{
    public function test_it_concatenates_fields(): void
    {
        $transform = ConcatTransform::fromString('concat(first_name, " ", last_name)');

        $context = [
            'row' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
        ];

        $result = $transform->transform('', $context);

        $this->assertEquals('John Doe', $result);
    }

    public function test_it_handles_missing_fields(): void
    {
        $transform = ConcatTransform::fromString('concat(first_name, " ", last_name)');

        $context = [
            'row' => [
                'first_name' => 'John',
            ],
        ];

        $result = $transform->transform('', $context);

        // Missing field should result in empty string or null
        $this->assertIsString($result);
    }

    public function test_it_handles_custom_separator(): void
    {
        $transform = ConcatTransform::fromString('concat(city, ", ", country)');

        $context = [
            'row' => [
                'city' => 'Milan',
                'country' => 'Italy',
            ],
        ];

        $result = $transform->transform('', $context);

        $this->assertEquals('Milan, Italy', $result);
    }
}
