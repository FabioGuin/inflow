<?php

namespace InFlow\Transforms\String;

use Illuminate\Support\Str;
use InFlow\Contracts\TransformStepInterface;

/**
 * Convert string to snake_case
 */
class SnakeCaseTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return Str::snake($value);
    }

    public function getName(): string
    {
        return 'snake_case';
    }
}
