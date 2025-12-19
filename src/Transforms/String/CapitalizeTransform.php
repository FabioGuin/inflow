<?php

namespace InFlow\Transforms\String;

use InFlow\Contracts\TransformStepInterface;

/**
 * Capitalize transformation (first letter uppercase, rest lowercase)
 */
class CapitalizeTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return ucfirst(strtolower($value));
    }

    public function getName(): string
    {
        return 'capitalize';
    }
}
