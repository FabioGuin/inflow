<?php

namespace InFlow\Tests\Unit\Transforms;

use InFlow\Transforms\TransformEngine;
use PHPUnit\Framework\TestCase;

class TransformEngineTest extends TestCase
{
    private TransformEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new TransformEngine;
    }

    public function test_it_applies_trim_transform(): void
    {
        $result = $this->engine->apply('  hello  ', ['trim']);

        $this->assertEquals('hello', $result);
    }

    public function test_it_applies_lower_transform(): void
    {
        $result = $this->engine->apply('HELLO', ['lower']);

        $this->assertEquals('hello', $result);
    }

    public function test_it_applies_upper_transform(): void
    {
        $result = $this->engine->apply('hello', ['upper']);

        $this->assertEquals('HELLO', $result);
    }

    public function test_it_applies_pipeline(): void
    {
        $result = $this->engine->applyPipeline('  HELLO  ', 'trim|lower');

        $this->assertEquals('hello', $result);
    }

    public function test_it_applies_cast_int(): void
    {
        $result = $this->engine->apply('123', ['cast:int']);

        $this->assertEquals(123, $result);
        $this->assertIsInt($result);
    }

    public function test_it_applies_cast_float(): void
    {
        $result = $this->engine->apply('123.45', ['cast:float']);

        $this->assertEquals(123.45, $result);
        $this->assertIsFloat($result);
    }

    public function test_it_applies_cast_bool(): void
    {
        $this->assertTrue($this->engine->apply('true', ['cast:bool']));
        $this->assertFalse($this->engine->apply('false', ['cast:bool']));
        $this->assertTrue($this->engine->apply('yes', ['cast:bool']));
        $this->assertFalse($this->engine->apply('no', ['cast:bool']));
    }

    public function test_it_applies_default_transform(): void
    {
        $result = $this->engine->apply(null, ['default:value']);

        $this->assertEquals('value', $result);
    }

    public function test_it_handles_empty_string_with_default(): void
    {
        $result = $this->engine->apply('', ['default:value']);

        $this->assertEquals('value', $result);
    }

    public function test_it_handles_multiple_transforms_in_sequence(): void
    {
        $result = $this->engine->apply('  HELLO WORLD  ', ['trim', 'lower', 'upper']);

        $this->assertEquals('HELLO WORLD', $result);
    }
}
