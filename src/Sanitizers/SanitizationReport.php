<?php

namespace InFlow\Sanitizers;

use InFlow\Contracts\SanitizationReportInterface;

/**
 * Value Object representing a sanitization report
 */
readonly class SanitizationReport implements SanitizationReportInterface
{
    public function __construct(
        private array $statistics,
        private array $decisions,
        private array $affectedRows
    ) {}

    /**
     * Returns statistics of found anomalies
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * Returns decisions made during sanitization
     */
    public function getDecisions(): array
    {
        return $this->decisions;
    }

    /**
     * Returns examples of rows affected by anomalies
     */
    public function getAffectedRows(): array
    {
        return $this->affectedRows;
    }

    /**
     * Returns the report as an array
     */
    public function toArray(): array
    {
        return [
            'statistics' => $this->statistics,
            'decisions' => $this->decisions,
            'affected_rows' => $this->affectedRows,
        ];
    }
}
