<?php

namespace InFlow\Transforms\String;

use InFlow\Contracts\TransformStepInterface;

/**
 * Trim whitespace transformation
 */
class TrimTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return trim($value);
    }

    public function getName(): string
    {
        return 'trim';
    }
}
