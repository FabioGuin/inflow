<?php

namespace InFlow\Transforms\Numeric;

use InFlow\Contracts\TransformStepInterface;

/**
 * Round number up to nearest integer
 */
class CeilTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return (int) ceil((float) $value);
    }

    public function getName(): string
    {
        return 'ceil';
    }
}
