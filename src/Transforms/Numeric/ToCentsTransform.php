<?php

namespace InFlow\Transforms\Numeric;

use InFlow\Contracts\TransformStepInterface;

/**
 * Convert decimal price to cents (integer)
 *
 * 12.99 → 1299
 * 100.00 → 10000
 */
class ToCentsTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return (int) round((float) $value * 100);
    }

    public function getName(): string
    {
        return 'to_cents';
    }
}
