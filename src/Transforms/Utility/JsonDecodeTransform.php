<?php

namespace InFlow\Transforms\Utility;

use InFlow\Contracts\TransformStepInterface;

/**
 * Decode JSON string to array/object
 */
class JsonDecodeTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        return $decoded;
    }

    public function getName(): string
    {
        return 'json_decode';
    }
}
