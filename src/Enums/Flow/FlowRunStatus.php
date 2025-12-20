<?php

namespace InFlow\Enums\Flow;

/**
 * Enum representing the status of a FlowRun execution
 */
enum FlowRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case PartiallyCompleted = 'partially_completed';

    /**
     * Check if the run is in a terminal state (cannot transition to other states)
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    /**
     * Check if the run is currently active (running or pending)
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }

    /**
     * Check if the run was successful (completed or partially completed)
     */
    public function isSuccessful(): bool
    {
        return in_array($this, [self::Completed, self::PartiallyCompleted], true);
    }

    /**
     * Get human-readable label for the status
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::PartiallyCompleted => 'Partially Completed',
        };
    }
}
