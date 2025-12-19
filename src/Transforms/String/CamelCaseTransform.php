<?php

namespace InFlow\Transforms\String;

use Illuminate\Support\Str;
use InFlow\Contracts\TransformStepInterface;

/**
 * Convert string to camelCase
 */
class CamelCaseTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return Str::camel($value);
    }

    public function getName(): string
    {
        return 'camel_case';
    }
}
