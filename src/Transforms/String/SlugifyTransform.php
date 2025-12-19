<?php

namespace InFlow\Transforms\String;

use Illuminate\Support\Str;
use InFlow\Contracts\TransformStepInterface;

/**
 * Convert string to URL-friendly slug
 */
class SlugifyTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return Str::slug($value);
    }

    public function getName(): string
    {
        return 'slugify';
    }
}
