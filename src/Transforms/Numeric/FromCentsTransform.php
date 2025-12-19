<?php

namespace InFlow\Transforms\Numeric;

use InFlow\Contracts\TransformStepInterface;

/**
 * Convert cents (integer) to decimal price
 *
 * 1299 → 12.99
 * 10000 → 100.00
 */
class FromCentsTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return (float) $value / 100;
    }

    public function getName(): string
    {
        return 'from_cents';
    }
}
