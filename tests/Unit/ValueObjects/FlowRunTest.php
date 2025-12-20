<?php

namespace InFlow\Tests\Unit\ValueObjects;

use InFlow\Enums\FlowRunStatus;
use InFlow\Tests\TestCase;
use InFlow\ValueObjects\Flow\FlowRun;

class FlowRunTest extends TestCase
{
    public function test_it_can_be_created_in_pending_state(): void
    {
        $run = FlowRun::create('/tmp/test.csv', 100);

        $this->assertEquals(FlowRunStatus::Pending, $run->status);
        $this->assertEquals(100, $run->totalRows);
        $this->assertEquals('/tmp/test.csv', $run->sourceFile);
        $this->assertNotNull($run->startTime);
    }

    public function test_it_can_start(): void
    {
        $run = FlowRun::create('/tmp/test.csv');
        $started = $run->start();

        $this->assertEquals(FlowRunStatus::Running, $started->status);
        $this->assertNotNull($started->startTime);
    }

    public function test_it_can_update_progress(): void
    {
        $run = FlowRun::create('/tmp/test.csv', 100)
            ->start()
            ->updateProgress(50, 10, 5);

        $this->assertEquals(50, $run->importedRows);
        $this->assertEquals(10, $run->skippedRows);
        $this->assertEquals(5, $run->errorCount);
        $this->assertEquals(65.0, $run->progress); // (50+10+5)/100 * 100
    }

    public function test_progress_is_capped_at_100(): void
    {
        $run = FlowRun::create('/tmp/test.csv', 100)
            ->start()
            ->updateProgress(150, 0, 0); // More than total

        $this->assertEquals(100.0, $run->progress);
    }

    public function test_it_can_add_errors(): void
    {
        $run = FlowRun::create('/tmp/test.csv')
            ->addError('Test error', 5, ['field' => 'email']);

        $this->assertEquals(1, $run->errorCount);
        $this->assertCount(1, $run->errors);
        $this->assertEquals('Test error', $run->errors[0]['message']);
        $this->assertEquals(5, $run->errors[0]['row']);
    }

    public function test_it_can_add_warnings(): void
    {
        $run = FlowRun::create('/tmp/test.csv')
            ->addWarning('Test warning', ['column' => 'age']);

        $this->assertCount(1, $run->warnings);
        $this->assertEquals('Test warning', $run->warnings[0]['message']);
    }

    public function test_it_can_complete_successfully(): void
    {
        $run = FlowRun::create('/tmp/test.csv', 100)
            ->start()
            ->updateProgress(100, 0, 0)
            ->complete();

        $this->assertEquals(FlowRunStatus::Completed, $run->status);
        $this->assertEquals(100.0, $run->progress);
        $this->assertNotNull($run->endTime);
    }

    public function test_it_completes_as_partially_completed_with_errors(): void
    {
        $run = FlowRun::create('/tmp/test.csv', 100)
            ->start()
            ->updateProgress(80, 0, 5) // 80 imported, 5 errors
            ->complete();

        $this->assertEquals(FlowRunStatus::PartiallyCompleted, $run->status);
        $this->assertEquals(100.0, $run->progress);
    }

    public function test_it_can_fail(): void
    {
        $exception = new \RuntimeException('Test exception');
        $run = FlowRun::create('/tmp/test.csv')
            ->start()
            ->fail('Execution failed', $exception);

        $this->assertEquals(FlowRunStatus::Failed, $run->status);
        $this->assertNotNull($run->endTime);
        $this->assertCount(1, $run->errors);
        $this->assertEquals('Execution failed', $run->errors[0]['message']);
    }

    public function test_it_calculates_duration(): void
    {
        $run = FlowRun::create('/tmp/test.csv')
            ->start();

        // Simulate some time passing
        usleep(100000); // 100ms

        $run = $run->complete();
        $duration = $run->getDuration();

        $this->assertNotNull($duration);
        $this->assertGreaterThan(0, $duration);
        $this->assertLessThan(1, $duration); // Should be less than 1 second
    }

    public function test_it_calculates_success_rate(): void
    {
        $run = FlowRun::create('/tmp/test.csv', 100)
            ->start()
            ->updateProgress(75, 10, 5)
            ->complete();

        $this->assertEquals(75.0, $run->getSuccessRate()); // 75/100 * 100
    }

    public function test_success_rate_is_zero_with_no_rows(): void
    {
        $run = FlowRun::create('/tmp/test.csv', 0)
            ->start()
            ->complete();

        $this->assertEquals(0.0, $run->getSuccessRate());
    }

    public function test_it_can_convert_to_array(): void
    {
        $run = FlowRun::create('/tmp/test.csv', 100)
            ->start()
            ->updateProgress(50, 10, 0) // No errors in progress update
            ->addError('Test error', 5) // Add one error
            ->addWarning('Test warning')
            ->complete();

        $array = $run->toArray();

        $this->assertIsArray($array);
        // With errors, status should be partially_completed
        $this->assertEquals('partially_completed', $array['status']);
        $this->assertEquals(100, $array['total_rows']);
        $this->assertEquals(50, $array['imported_rows']);
        $this->assertEquals(10, $array['skipped_rows']);
        $this->assertEquals(1, $array['error_count']); // One error from addError
        $this->assertArrayHasKey('duration', $array);
        $this->assertArrayHasKey('success_rate', $array);
        $this->assertArrayHasKey('errors', $array);
        $this->assertArrayHasKey('warnings', $array);
    }
}
