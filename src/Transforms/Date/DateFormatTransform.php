<?php

namespace InFlow\Transforms\Date;

use Carbon\Carbon;
use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Format date to specified format
 *
 * Usage: date_format:Y-m-d
 */
readonly class DateFormatTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private string $format = 'Y-m-d'
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            $date = Carbon::parse($value);

            return $date->format($this->format);
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', ['operation' => 'dateFormat', 'format' => $this->format, 'value' => $value]);

            return $value;
        }
    }

    public function getName(): string
    {
        return 'date_format';
    }

    public static function fromString(string $definition): self
    {
        $parts = explode(':', $definition, 2);

        return new self($parts[1] ?? 'Y-m-d');
    }

    public static function getPrompts(): array
    {
        return [
            [
                'label' => 'What OUTPUT format do you want?',
                'hint' => 'This is for EXPORT/display only, not for importing into DB',
                'examples' => ['Y-m-d (2024-12-31)', 'd/m/Y (31/12/2024)', 'F j, Y (December 31, 2024)'],
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $format = $responses[0] ?? null;

        return $format ? "date_format:{$format}" : null;
    }
}
