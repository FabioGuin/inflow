<?php

namespace InFlow\Tests\Unit\Transforms;

use InFlow\Transforms\String\CleanWhitespaceTransform;
use InFlow\Transforms\String\NormalizeMultilineTransform;
use PHPUnit\Framework\TestCase;

class WhitespaceTransformTest extends TestCase
{
    // ==========================================
    // CleanWhitespaceTransform Tests
    // ==========================================

    public function test_clean_whitespace_trims_edges(): void
    {
        $transform = new CleanWhitespaceTransform;

        $this->assertEquals('hello', $transform->transform('  hello  '));
        $this->assertEquals('hello', $transform->transform("\thello\t"));
        $this->assertEquals('hello', $transform->transform("\nhello\n"));
    }

    public function test_clean_whitespace_replaces_tabs_with_space(): void
    {
        $transform = new CleanWhitespaceTransform;

        $this->assertEquals('John Doe', $transform->transform("John\tDoe"));
        $this->assertEquals('John Doe', $transform->transform("John\t\tDoe"));
        $this->assertEquals('a b c', $transform->transform("a\tb\tc"));
    }

    public function test_clean_whitespace_replaces_newlines_with_space(): void
    {
        $transform = new CleanWhitespaceTransform;

        $this->assertEquals('John Doe', $transform->transform("John\nDoe"));
        $this->assertEquals('John Doe', $transform->transform("John\r\nDoe"));
        $this->assertEquals('a b c', $transform->transform("a\nb\nc"));
    }

    public function test_clean_whitespace_collapses_multiple_spaces(): void
    {
        $transform = new CleanWhitespaceTransform;

        $this->assertEquals('Multiple Spaces', $transform->transform('Multiple   Spaces'));
        $this->assertEquals('a b c', $transform->transform('a    b    c'));
    }

    public function test_clean_whitespace_handles_mixed_whitespace(): void
    {
        $transform = new CleanWhitespaceTransform;

        $this->assertEquals('John Doe', $transform->transform("  John\t\tDoe\n "));
        $this->assertEquals('Hello World', $transform->transform("Hello\r\n\t  World"));
    }

    public function test_clean_whitespace_handles_null_and_non_string(): void
    {
        $transform = new CleanWhitespaceTransform;

        $this->assertNull($transform->transform(null));
        $this->assertEquals(123, $transform->transform(123));
        $this->assertEquals(['a'], $transform->transform(['a']));
    }

    public function test_clean_whitespace_returns_name(): void
    {
        $transform = new CleanWhitespaceTransform;

        $this->assertEquals('clean_whitespace', $transform->getName());
    }

    // ==========================================
    // NormalizeMultilineTransform Tests
    // ==========================================

    public function test_normalize_multiline_preserves_single_newlines(): void
    {
        $transform = new NormalizeMultilineTransform;

        $this->assertEquals("Line 1\nLine 2", $transform->transform("Line 1\nLine 2"));
        $this->assertEquals("Paragraph 1\n\nParagraph 2", $transform->transform("Paragraph 1\n\nParagraph 2"));
    }

    public function test_normalize_multiline_normalizes_line_endings(): void
    {
        $transform = new NormalizeMultilineTransform;

        // \r\n (Windows) → \n
        $this->assertEquals("Line 1\nLine 2", $transform->transform("Line 1\r\nLine 2"));
        // \r (old Mac) → \n
        $this->assertEquals("Line 1\nLine 2", $transform->transform("Line 1\rLine 2"));
    }

    public function test_normalize_multiline_replaces_tabs_with_space(): void
    {
        $transform = new NormalizeMultilineTransform;

        $this->assertEquals('Hello World', $transform->transform("Hello\tWorld"));
        $this->assertEquals("Line 1 text\nLine 2", $transform->transform("Line 1\ttext\nLine 2"));
    }

    public function test_normalize_multiline_collapses_multiple_spaces_per_line(): void
    {
        $transform = new NormalizeMultilineTransform;

        $this->assertEquals('Hello World', $transform->transform('Hello    World'));
        $this->assertEquals("Line 1\nLine 2", $transform->transform("Line 1   \n   Line 2"));
    }

    public function test_normalize_multiline_limits_consecutive_newlines(): void
    {
        $transform = new NormalizeMultilineTransform;

        // 3+ newlines → 2 (max one blank line)
        $this->assertEquals("Para 1\n\nPara 2", $transform->transform("Para 1\n\n\nPara 2"));
        $this->assertEquals("Para 1\n\nPara 2", $transform->transform("Para 1\n\n\n\n\nPara 2"));
    }

    public function test_normalize_multiline_trims_each_line(): void
    {
        $transform = new NormalizeMultilineTransform;

        $this->assertEquals("Line 1\nLine 2", $transform->transform("  Line 1  \n  Line 2  "));
    }

    public function test_normalize_multiline_trims_entire_text(): void
    {
        $transform = new NormalizeMultilineTransform;

        $this->assertEquals('Content', $transform->transform("\n\nContent\n\n"));
        $this->assertEquals("Line 1\nLine 2", $transform->transform("\n\nLine 1\nLine 2\n\n"));
    }

    public function test_normalize_multiline_handles_complex_case(): void
    {
        $transform = new NormalizeMultilineTransform;

        $input = "  Paragraph 1\t\ttext  \r\n\r\n\r\n  Paragraph 2  ";
        $expected = "Paragraph 1 text\n\nParagraph 2";

        $this->assertEquals($expected, $transform->transform($input));
    }

    public function test_normalize_multiline_handles_null_and_non_string(): void
    {
        $transform = new NormalizeMultilineTransform;

        $this->assertNull($transform->transform(null));
        $this->assertEquals(123, $transform->transform(123));
        $this->assertEquals(['a'], $transform->transform(['a']));
    }

    public function test_normalize_multiline_returns_name(): void
    {
        $transform = new NormalizeMultilineTransform;

        $this->assertEquals('normalize_multiline', $transform->getName());
    }
}
