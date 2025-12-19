<?php

namespace InFlow\Transforms\String;

use InFlow\Contracts\TransformStepInterface;

/**
 * Convert to uppercase transformation
 */
class UpperTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return strtoupper($value);
    }

    public function getName(): string
    {
        return 'upper';
    }
}
