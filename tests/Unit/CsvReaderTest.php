<?php

namespace InFlow\Tests\Unit;

use InFlow\Detectors\FormatDetector;
use InFlow\Enums\FileType;
use InFlow\Readers\CsvReader;
use InFlow\Sources\FileSource;
use InFlow\Tests\TestCase;
use InFlow\ValueObjects\File\DetectedFormat;

class CsvReaderTest extends TestCase
{
    /** @test */
    public function it_can_read_csv_with_header()
    {
        $this->createTestFile('test.csv', "name,email,age\nJohn,john@example.com,30\nJane,jane@example.com,25");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);

        $reader = new CsvReader($source, $format);

        $rows = [];
        foreach ($reader as $row) {
            $rows[] = $row;
        }

        $this->assertCount(2, $rows);
        $this->assertEquals(['name' => 'John', 'email' => 'john@example.com', 'age' => '30'], $rows[0]);
        $this->assertEquals(['name' => 'Jane', 'email' => 'jane@example.com', 'age' => '25'], $rows[1]);
    }

    /** @test */
    public function it_can_read_csv_without_header()
    {
        $this->createTestFile('test.csv', "1,2,3\n4,5,6\n7,8,9");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);

        $reader = new CsvReader($source, $format);

        $rows = [];
        foreach ($reader as $row) {
            $rows[] = $row;
        }

        $this->assertCount(3, $rows);
        $this->assertEquals(['1', '2', '3'], $rows[0]);
    }

    /** @test */
    public function it_can_read_csv_with_quoted_fields()
    {
        $this->createTestFile('test.csv', '"name","email","age"'."\n".'"John Doe","john@example.com","30"');

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);

        $reader = new CsvReader($source, $format);

        $rows = [];
        foreach ($reader as $row) {
            $rows[] = $row;
        }

        $this->assertCount(1, $rows);
        $this->assertEquals(['name' => 'John Doe', 'email' => 'john@example.com', 'age' => '30'], $rows[0]);
    }

    /** @test */
    public function it_can_read_csv_with_semicolon_delimiter()
    {
        $this->createTestFile('test.csv', "name;email;age\nJohn;john@example.com;30");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $detector = new FormatDetector;
        $format = $detector->detect($source);

        $reader = new CsvReader($source, $format);

        $rows = [];
        foreach ($reader as $row) {
            $rows[] = $row;
        }

        $this->assertCount(1, $rows);
        $this->assertEquals(['name' => 'John', 'email' => 'john@example.com', 'age' => '30'], $rows[0]);
    }

    /** @test */
    public function it_implements_iterator_interface()
    {
        $this->createTestFile('test.csv', "name,email\nJohn,john@example.com\nJane,jane@example.com");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $format = new DetectedFormat(FileType::Csv, ',', '"', true, 'UTF-8');
        $reader = new CsvReader($source, $format);

        $this->assertInstanceOf(\Iterator::class, $reader);
        $this->assertTrue($reader->valid());
        $this->assertEquals(0, $reader->key());
    }

    /** @test */
    public function it_can_rewind()
    {
        $this->createTestFile('test.csv', "name,email\nJohn,john@example.com\nJane,jane@example.com");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $format = new DetectedFormat(FileType::Csv, ',', '"', true, 'UTF-8');
        $reader = new CsvReader($source, $format);

        $firstPass = [];
        foreach ($reader as $row) {
            $firstPass[] = $row;
        }

        $reader->rewind();

        $secondPass = [];
        foreach ($reader as $row) {
            $secondPass[] = $row;
        }

        $this->assertEquals($firstPass, $secondPass);
    }

    /** @test */
    public function it_returns_headers_when_available()
    {
        $this->createTestFile('test.csv', "name,email,age\nJohn,john@example.com,30");

        $source = FileSource::fromPath(__DIR__.'/../Fixtures/test.csv');
        $format = new DetectedFormat(FileType::Csv, ',', '"', true, 'UTF-8');
        $reader = new CsvReader($source, $format);

        $this->assertEquals(['name', 'email', 'age'], $reader->getHeaders());
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
