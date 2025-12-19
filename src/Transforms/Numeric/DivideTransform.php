<?php

namespace InFlow\Transforms\Numeric;

use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Divide number by divisor
 *
 * Usage: divide:100
 */
readonly class DivideTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private float $divisor = 1
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        if ($this->divisor == 0) {
            return $value;
        }

        return (float) $value / $this->divisor;
    }

    public function getName(): string
    {
        return 'divide';
    }

    public static function fromString(string $definition): self
    {
        $parts = explode(':', $definition, 2);
        $divisor = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 1;

        return new self($divisor);
    }

    public static function getPrompts(): array
    {
        return [
            [
                'label' => 'Enter divisor',
                'hint' => 'Number to divide by',
                'examples' => ['100 (from cents)', '1000 (to thousands)', '2 (half)'],
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $divisor = $responses[0] ?? null;

        return (is_numeric($divisor) && (float) $divisor != 0) ? "divide:{$divisor}" : null;
    }
}
