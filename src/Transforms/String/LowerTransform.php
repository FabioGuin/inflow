<?php

namespace InFlow\Transforms\String;

use InFlow\Contracts\TransformStepInterface;

/**
 * Convert to lowercase transformation
 */
class LowerTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return strtolower($value);
    }

    public function getName(): string
    {
        return 'lower';
    }
}
