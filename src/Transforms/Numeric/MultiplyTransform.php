<?php

namespace InFlow\Transforms\Numeric;

use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Multiply number by factor
 *
 * Usage: multiply:100
 */
readonly class MultiplyTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private float $factor = 1
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return (float) $value * $this->factor;
    }

    public function getName(): string
    {
        return 'multiply';
    }

    public static function fromString(string $definition): self
    {
        $parts = explode(':', $definition, 2);
        $factor = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 1;

        return new self($factor);
    }

    public static function getPrompts(): array
    {
        return [
            [
                'label' => 'Enter multiplier',
                'hint' => 'Factor to multiply by',
                'examples' => ['100 (to cents)', '1.1 (10% increase)', '0.5 (half)'],
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $factor = $responses[0] ?? null;

        return (is_numeric($factor)) ? "multiply:{$factor}" : null;
    }
}
