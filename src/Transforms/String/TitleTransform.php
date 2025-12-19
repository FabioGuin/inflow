<?php

namespace InFlow\Transforms\String;

use Illuminate\Support\Str;
use InFlow\Contracts\TransformStepInterface;

/**
 * Convert string to Title Case (capitalize each word)
 */
class TitleTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return Str::title($value);
    }

    public function getName(): string
    {
        return 'title';
    }
}
