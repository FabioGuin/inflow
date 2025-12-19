<?php

namespace InFlow\Contracts;

interface SanitizationReportInterface
{
    /**
     * Returns statistics of found anomalies
     */
    public function getStatistics(): array;

    /**
     * Returns decisions made during sanitization
     */
    public function getDecisions(): array;

    /**
     * Returns examples of rows affected by anomalies
     */
    public function getAffectedRows(): array;
}
