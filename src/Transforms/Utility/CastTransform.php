<?php

namespace InFlow\Transforms\Utility;

use InFlow\Contracts\TransformStepInterface;

/**
 * Cast value to a specific type transformation
 */
class CastTransform implements TransformStepInterface
{
    public function __construct(
        private string $type
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => $this->castToBool($value),
            'date', 'datetime' => $this->castToDate($value),
            'string' => (string) $value,
            default => $value,
        };
    }

    public function getName(): string
    {
        return "cast:{$this->type}";
    }

    /**
     * Cast value to boolean
     */
    private function castToBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, ['true', 'yes', 'y', '1', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['false', 'no', 'n', '0', 'off'], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    /**
     * Cast value to date
     *
     * Logs a warning when date parsing fails or produces unexpected results.
     */
    private function castToDate(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $originalValue = $value;

        // Try to parse as date
        $timestamp = is_numeric($value) ? (int) $value : strtotime($value);

        if ($timestamp === false) {
            \inflow_report(
                new \RuntimeException("Date parsing failed for value: {$originalValue}"),
                'warning',
                [
                    'operation' => 'castToDate',
                    'value' => $originalValue,
                    'reason' => 'strtotime returned false',
                ]
            );

            return null;
        }

        // Detect suspicious results (epoch or very old dates from bad parsing)
        // strtotime('2015') returns epoch, strtotime('current') returns false
        if ($timestamp <= 0) {
            \inflow_report(
                new \RuntimeException("Date parsing produced invalid timestamp for value: {$originalValue}"),
                'warning',
                [
                    'operation' => 'castToDate',
                    'value' => $originalValue,
                    'timestamp' => $timestamp,
                    'reason' => 'timestamp <= 0 (possibly epoch fallback)',
                ]
            );

            return null;
        }

        // Additional check: if input looks like a year-only string but parsed to epoch
        if (is_string($originalValue) && preg_match('/^\d{4}$/', trim($originalValue))) {
            $parsedYear = (int) date('Y', $timestamp);
            $inputYear = (int) trim($originalValue);

            if ($parsedYear !== $inputYear) {
                \inflow_report(
                    new \RuntimeException("Year-only date '{$originalValue}' parsed incorrectly to year {$parsedYear}"),
                    'warning',
                    [
                        'operation' => 'castToDate',
                        'value' => $originalValue,
                        'expected_year' => $inputYear,
                        'parsed_year' => $parsedYear,
                        'reason' => 'year mismatch - ambiguous format',
                    ]
                );

                // Return January 1st of that year as a reasonable fallback
                return "{$inputYear}-01-01 00:00:00";
            }
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Create a CastTransform from a string specification (e.g., "cast:int")
     */
    public static function fromString(string $spec): self
    {
        if (! str_starts_with($spec, 'cast:')) {
            throw new \InvalidArgumentException("Invalid cast specification: {$spec}");
        }

        $type = substr($spec, 5);

        return new self($type);
    }
}
