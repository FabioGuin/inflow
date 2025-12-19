<?php

namespace InFlow\Tests\Unit\Transforms;

use InFlow\Transforms\RegexReplaceTransform;
use PHPUnit\Framework\TestCase;

class RegexReplaceTransformTest extends TestCase
{
    public function test_it_replaces_pattern(): void
    {
        $transform = RegexReplaceTransform::fromString('regex_replace(/\\s+/g, "-")');

        $result = $transform->transform('hello world test');

        $this->assertEquals('hello-world-test', $result);
    }

    public function test_it_removes_characters(): void
    {
        $transform = RegexReplaceTransform::fromString('regex_replace(/[^0-9]/g, "")');

        $result = $transform->transform('abc123def456');

        $this->assertEquals('123456', $result);
    }

    public function test_it_handles_empty_string(): void
    {
        $transform = RegexReplaceTransform::fromString('regex_replace(/test/g, "")');

        $result = $transform->transform('');

        $this->assertEquals('', $result);
    }

    public function test_it_handles_null_value(): void
    {
        $transform = RegexReplaceTransform::fromString('regex_replace(/test/g, "")');

        $result = $transform->transform(null);

        $this->assertNull($result);
    }
}
