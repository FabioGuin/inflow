<?php

namespace InFlow\Tests\Unit;

use InFlow\Detectors\FormatDetector;
use InFlow\Enums\FileType;
use InFlow\Sources\FileSource;
use InFlow\Tests\TestCase;
use InFlow\ValueObjects\DetectedFormat;

class FormatDetectorTest extends TestCase
{
    /** @test */
    public function it_detects_csv_format_with_comma_delimiter()
    {
        $this->createTestFile('test.csv', "name,email,age\nJohn,john@example.com,30\nJane,jane@example.com,25");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);

        $this->assertInstanceOf(DetectedFormat::class, $format);
        $this->assertEquals(FileType::Csv, $format->type);
        $this->assertEquals(',', $format->delimiter);
        $this->assertTrue($format->isValid());
    }

    /** @test */
    public function it_detects_semicolon_delimiter()
    {
        $this->createTestFile('test.csv', "name;email;age\nJohn;john@example.com;30\nJane;jane@example.com;25");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);

        $this->assertEquals(';', $format->delimiter);
    }

    /** @test */
    public function it_detects_tab_delimiter()
    {
        $this->createTestFile('test.csv', "name\temail\tage\nJohn\tjohn@example.com\t30\nJane\tjane@example.com\t25");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);

        $this->assertEquals("\t", $format->delimiter);
    }

    /** @test */
    public function it_detects_header_presence()
    {
        $this->createTestFile('test.csv', "name,email,age\nJohn,john@example.com,30\nJane,jane@example.com,25");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);

        $this->assertTrue($format->hasHeader);
    }

    /** @test */
    public function it_detects_no_header_when_first_line_is_numeric()
    {
        $this->createTestFile('test.csv', "1,2,3\n4,5,6\n7,8,9");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);

        $this->assertFalse($format->hasHeader);
    }

    /** @test */
    public function it_detects_quote_character()
    {
        $this->createTestFile('test.csv', '"name","email","age"'."\n".'"John","john@example.com","30"');

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);

        $this->assertEquals('"', $format->quoteChar);
    }

    /** @test */
    public function it_detects_encoding()
    {
        $this->createTestFile('test.csv', "name,email,age\nJohn,john@example.com,30");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);

        $this->assertNotEmpty($format->encoding);
        $this->assertIsString($format->encoding);
    }

    /** @test */
    public function it_detects_file_type_from_extension()
    {
        $this->createTestFile('test.csv', "name,email\nJohn,john@example.com");
        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);
        $this->assertEquals(FileType::Csv, $format->type);

        $this->createTestFile('test.txt', "name,email\nJohn,john@example.com");
        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.txt');
        $format = $detector->detect($source);
        $this->assertEquals(FileType::Txt, $format->type);
    }

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanupTestFiles();
    }

    private function createTestFile(string $filename, string $content): void
    {
        file_put_contents(__DIR__.'/../Fixtures/'.$filename, $content);
    }

    private function cleanupTestFiles(): void
    {
        $files = glob(__DIR__.'/../Fixtures/test.*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
