<?php

namespace InFlow\Tests\Unit\Enums;

use InFlow\Enums\FlowRunStatus;
use InFlow\Tests\TestCase;

class FlowRunStatusTest extends TestCase
{
    public function test_it_has_all_expected_statuses(): void
    {
        $statuses = FlowRunStatus::cases();

        $this->assertCount(5, $statuses);
        $this->assertContains(FlowRunStatus::Pending, $statuses);
        $this->assertContains(FlowRunStatus::Running, $statuses);
        $this->assertContains(FlowRunStatus::Completed, $statuses);
        $this->assertContains(FlowRunStatus::Failed, $statuses);
        $this->assertContains(FlowRunStatus::PartiallyCompleted, $statuses);
    }

    public function test_terminal_statuses(): void
    {
        $this->assertTrue(FlowRunStatus::Completed->isTerminal());
        $this->assertTrue(FlowRunStatus::Failed->isTerminal());
        $this->assertFalse(FlowRunStatus::Pending->isTerminal());
        $this->assertFalse(FlowRunStatus::Running->isTerminal());
        $this->assertFalse(FlowRunStatus::PartiallyCompleted->isTerminal());
    }

    public function test_active_statuses(): void
    {
        $this->assertTrue(FlowRunStatus::Pending->isActive());
        $this->assertTrue(FlowRunStatus::Running->isActive());
        $this->assertFalse(FlowRunStatus::Completed->isActive());
        $this->assertFalse(FlowRunStatus::Failed->isActive());
        $this->assertFalse(FlowRunStatus::PartiallyCompleted->isActive());
    }

    public function test_successful_statuses(): void
    {
        $this->assertTrue(FlowRunStatus::Completed->isSuccessful());
        $this->assertTrue(FlowRunStatus::PartiallyCompleted->isSuccessful());
        $this->assertFalse(FlowRunStatus::Failed->isSuccessful());
        $this->assertFalse(FlowRunStatus::Pending->isSuccessful());
        $this->assertFalse(FlowRunStatus::Running->isSuccessful());
    }

    public function test_labels(): void
    {
        $this->assertEquals('Pending', FlowRunStatus::Pending->label());
        $this->assertEquals('Running', FlowRunStatus::Running->label());
        $this->assertEquals('Completed', FlowRunStatus::Completed->label());
        $this->assertEquals('Failed', FlowRunStatus::Failed->label());
        $this->assertEquals('Partially Completed', FlowRunStatus::PartiallyCompleted->label());
    }
}
