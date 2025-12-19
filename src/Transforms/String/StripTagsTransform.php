<?php

namespace InFlow\Transforms\String;

use InFlow\Contracts\TransformStepInterface;

/**
 * Remove HTML/XML tags from string
 */
class StripTagsTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return strip_tags($value);
    }

    public function getName(): string
    {
        return 'strip_tags';
    }
}
