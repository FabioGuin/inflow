<?php

namespace InFlow\Transforms\Date;

use Carbon\Carbon;
use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Parse date from specific format
 *
 * Usage: parse_date:d/m/Y (input is in this format, output is Y-m-d H:i:s)
 */
readonly class ParseDateTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private string $inputFormat = 'd/m/Y'
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            $date = Carbon::createFromFormat($this->inputFormat, $value);

            return $date?->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', ['operation' => 'parseDate', 'format' => $this->inputFormat, 'value' => $value]);

            return $value;
        }
    }

    public function getName(): string
    {
        return 'parse_date';
    }

    public static function fromString(string $definition): self
    {
        $parts = explode(':', $definition, 2);

        return new self($parts[1] ?? 'd/m/Y');
    }

    public static function getPrompts(): array
    {
        return [
            [
                'label' => 'What format is YOUR DATA in? (not the DB format)',
                'hint' => 'This tells us how to READ your dates. PHP format: d=day, m=month, Y=4-digit year',
                'examples' => ['d/m/Y (if your data looks like 31/12/2024)', 'm/d/Y (if 12/31/2024)', 'Y-m-d (if 2024-12-31)', 'F d Y (if December 31 2024)'],
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $format = $responses[0] ?? null;

        return $format ? "parse_date:{$format}" : null;
    }
}
