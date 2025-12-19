<?php

namespace InFlow\Tests\Unit\Transforms;

use InFlow\Transforms\CastTransform;
use PHPUnit\Framework\TestCase;

class CastTransformTest extends TestCase
{
    public function test_it_casts_to_int(): void
    {
        $transform = new CastTransform('int');

        $this->assertEquals(123, $transform->transform('123'));
        $this->assertEquals(0, $transform->transform(''));
        $this->assertIsInt($transform->transform('456'));
    }

    public function test_it_casts_to_float(): void
    {
        $transform = new CastTransform('float');

        $this->assertEquals(123.45, $transform->transform('123.45'));
        $this->assertEquals(0.0, $transform->transform(''));
        $this->assertIsFloat($transform->transform('456.78'));
    }

    public function test_it_casts_to_bool(): void
    {
        $transform = new CastTransform('bool');

        $this->assertTrue($transform->transform('true'));
        $this->assertTrue($transform->transform('yes'));
        $this->assertTrue($transform->transform('1'));
        $this->assertFalse($transform->transform('false'));
        $this->assertFalse($transform->transform('no'));
        $this->assertFalse($transform->transform('0'));
    }

    public function test_it_casts_to_date(): void
    {
        $transform = new CastTransform('date');

        $result = $transform->transform('2024-01-01');
        $this->assertIsString($result);
        $this->assertStringContainsString('2024-01-01', $result);

        $this->assertNull($transform->transform('invalid-date'));
    }

    public function test_it_casts_to_string(): void
    {
        $transform = new CastTransform('string');

        $this->assertEquals('123', $transform->transform(123));
        $this->assertEquals('45.67', $transform->transform(45.67));
    }

    public function test_it_handles_null_values(): void
    {
        $transform = new CastTransform('int');

        $this->assertNull($transform->transform(null));
        $this->assertNull($transform->transform(''));
    }

    public function test_it_can_be_created_from_string(): void
    {
        $transform = CastTransform::fromString('cast:int');

        $this->assertInstanceOf(CastTransform::class, $transform);
        $this->assertEquals(123, $transform->transform('123'));
    }
}
