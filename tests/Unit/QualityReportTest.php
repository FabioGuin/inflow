<?php

namespace InFlow\Tests\Unit;

use InFlow\ValueObjects\Data\QualityReport;
use Orchestra\Testbench\TestCase;

class QualityReportTest extends TestCase
{
    public function test_it_can_be_created_with_warnings_and_errors(): void
    {
        $report = new QualityReport(
            warnings: ['Warning 1', 'Warning 2'],
            errors: ['Error 1'],
            anomalies: ['column1' => ['duplicates' => ['value1' => 2]]]
        );

        $this->assertTrue($report->hasIssues());
        $this->assertTrue($report->hasErrors());
        // Total issues = warnings + errors + anomalies (anomalies are separate from warnings/errors)
        $this->assertEquals(4, $report->getTotalIssues());
        $this->assertCount(2, $report->warnings);
        $this->assertCount(1, $report->errors);
    }

    public function test_it_returns_false_when_no_issues(): void
    {
        $report = new QualityReport;

        $this->assertFalse($report->hasIssues());
        $this->assertFalse($report->hasErrors());
        $this->assertEquals(0, $report->getTotalIssues());
    }

    public function test_it_can_convert_to_array(): void
    {
        $report = new QualityReport(
            warnings: ['Warning 1'],
            errors: ['Error 1'],
            anomalies: ['column1' => ['duplicates' => []]]
        );

        $array = $report->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('warnings', $array);
        $this->assertArrayHasKey('errors', $array);
        $this->assertArrayHasKey('anomalies', $array);
        $this->assertArrayHasKey('has_issues', $array);
        $this->assertArrayHasKey('has_errors', $array);
        $this->assertArrayHasKey('total_issues', $array);
        $this->assertTrue($array['has_issues']);
    }
}
