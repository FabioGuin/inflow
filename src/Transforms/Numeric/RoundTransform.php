<?php

namespace InFlow\Transforms\Numeric;

use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Round number to N decimal places
 *
 * Usage: round:2
 */
readonly class RoundTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private int $precision = 0
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return round((float) $value, $this->precision);
    }

    public function getName(): string
    {
        return 'round';
    }

    public static function fromString(string $definition): self
    {
        $parts = explode(':', $definition, 2);
        $precision = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;

        return new self($precision);
    }

    public static function getPrompts(): array
    {
        return [
            [
                'label' => 'Enter decimal places',
                'hint' => 'Number of decimal places to round to',
                'examples' => ['0 (integer)', '2 (cents)', '4 (precision)'],
                'default' => '2',
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $precision = $responses[0] ?? '0';

        return is_numeric($precision) ? "round:{$precision}" : null;
    }
}
