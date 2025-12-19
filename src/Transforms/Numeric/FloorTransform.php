<?php

namespace InFlow\Transforms\Numeric;

use InFlow\Contracts\TransformStepInterface;

/**
 * Round number down to nearest integer
 */
class FloorTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return (int) floor((float) $value);
    }

    public function getName(): string
    {
        return 'floor';
    }
}
