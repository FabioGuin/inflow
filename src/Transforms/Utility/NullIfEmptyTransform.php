<?php

namespace InFlow\Transforms\Utility;

use InFlow\Contracts\TransformStepInterface;

/**
 * Convert empty strings to null
 */
class NullIfEmptyTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $value;
    }

    public function getName(): string
    {
        return 'null_if_empty';
    }
}
