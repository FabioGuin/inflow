<?php

namespace InFlow\Transforms\String;

use InFlow\Contracts\TransformStepInterface;

/**
 * Clean whitespace for single-line fields.
 *
 * Ideal for: name, email, sku, code, title (single-line fields)
 *
 * Operations:
 * - Replaces tabs (\t) with single space
 * - Replaces newlines (\r\n, \n, \r) with single space
 * - Collapses multiple spaces into single space
 * - Trims leading/trailing whitespace
 *
 * Usage: clean_whitespace
 *
 * @example "  John\t\tDoe\n " → "John Doe"
 * @example "Multiple   Spaces" → "Multiple Spaces"
 */
class CleanWhitespaceTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        // Replace tabs and newlines with single space
        $cleaned = preg_replace('/[\t\r\n]+/', ' ', $value);

        // Collapse multiple spaces into single space
        $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned);

        // Trim leading/trailing whitespace
        return trim($cleaned);
    }

    public function getName(): string
    {
        return 'clean_whitespace';
    }
}
