<?php

namespace InFlow\Tests\Unit;

use InFlow\Enums\FileType;
use InFlow\Readers\ExcelReader;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\File\DetectedFormat;
use Orchestra\Testbench\TestCase;

class ExcelReaderTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesPath = __DIR__.'/../Fixtures/';
    }

    public function test_it_reads_excel_file_with_header(): void
    {
        $source = FileSource::fromPath($this->fixturesPath.'test_excel.xlsx');
        $format = new DetectedFormat(
            type: FileType::Xlsx,
            delimiter: ',',
            quoteChar: '"',
            hasHeader: true,
            encoding: 'UTF-8'
        );

        $reader = new ExcelReader($source, $format);

        $rows = [];
        foreach ($reader as $row) {
            $rows[] = $row;
        }

        $this->assertCount(5, $rows);
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('email', $rows[0]);
        $this->assertEquals('1', $rows[0]['id']);
    }

    public function test_it_reads_excel_file_without_header(): void
    {
        // Note: ExcelReader auto-detects headers, so even with hasHeader=false,
        // it will detect headers if first row looks like headers
        $source = FileSource::fromPath($this->fixturesPath.'test_excel.xlsx');
        $format = new DetectedFormat(
            type: FileType::Xlsx,
            delimiter: ',',
            quoteChar: '"',
            hasHeader: false,
            encoding: 'UTF-8'
        );

        $reader = new ExcelReader($source, $format);

        $rows = [];
        foreach ($reader as $row) {
            $rows[] = $row;
        }

        // ExcelReader auto-detects header, so should have 5 data rows
        $this->assertCount(5, $rows);
        $this->assertIsArray($rows[0]);
        // Auto-detection will create associative array
        $this->assertArrayHasKey('id', $rows[0]);
    }

    public function test_it_auto_detects_header_when_not_specified(): void
    {
        $source = FileSource::fromPath($this->fixturesPath.'test_excel.xlsx');
        $format = new DetectedFormat(
            type: FileType::Xlsx,
            delimiter: ',',
            quoteChar: '"',
            hasHeader: false, // Format says no header, but ExcelReader should auto-detect
            encoding: 'UTF-8'
        );

        $reader = new ExcelReader($source, $format);

        // Should auto-detect header and return associative arrays
        $firstRow = $reader->current();
        $this->assertArrayHasKey('id', $firstRow);
    }

    public function test_it_implements_iterator_interface(): void
    {
        $source = FileSource::fromPath($this->fixturesPath.'test_excel.xlsx');
        $format = new DetectedFormat(
            type: FileType::Xlsx,
            delimiter: ',',
            quoteChar: '"',
            hasHeader: true,
            encoding: 'UTF-8'
        );

        $reader = new ExcelReader($source, $format);

        $this->assertInstanceOf(\Iterator::class, $reader);
        $this->assertIsInt($reader->key());
        $this->assertIsArray($reader->current());
    }

    public function test_it_returns_headers_when_available(): void
    {
        $source = FileSource::fromPath($this->fixturesPath.'test_excel.xlsx');
        $format = new DetectedFormat(
            type: FileType::Xlsx,
            delimiter: ',',
            quoteChar: '"',
            hasHeader: true,
            encoding: 'UTF-8'
        );

        $reader = new ExcelReader($source, $format);

        $headers = $reader->getHeaders();
        $this->assertIsArray($headers);
        $this->assertContains('id', $headers);
        $this->assertContains('name', $headers);
    }

    public function test_it_returns_total_rows(): void
    {
        $source = FileSource::fromPath($this->fixturesPath.'test_excel.xlsx');
        $format = new DetectedFormat(
            type: FileType::Xlsx,
            delimiter: ',',
            quoteChar: '"',
            hasHeader: true,
            encoding: 'UTF-8'
        );

        $reader = new ExcelReader($source, $format);

        $totalRows = $reader->getTotalRows();
        $this->assertEquals(5, $totalRows);
    }

    public function test_it_can_rewind(): void
    {
        $source = FileSource::fromPath($this->fixturesPath.'test_excel.xlsx');
        $format = new DetectedFormat(
            type: FileType::Xlsx,
            delimiter: ',',
            quoteChar: '"',
            hasHeader: true,
            encoding: 'UTF-8'
        );

        $reader = new ExcelReader($source, $format);

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
}
