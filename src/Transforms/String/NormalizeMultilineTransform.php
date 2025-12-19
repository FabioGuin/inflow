<?php

namespace InFlow\Transforms\String;

use InFlow\Contracts\TransformStepInterface;

/**
 * Normalize whitespace for multi-line fields.
 *
 * Ideal for: description, bio, notes, content, address (multi-line fields)
 *
 * Operations:
 * - PRESERVES meaningful newlines (paragraph breaks)
 * - Normalizes line endings to \n (LF)
 * - Replaces tabs (\t) with single space
 * - Collapses multiple spaces (on same line) into single space
 * - Collapses 3+ consecutive newlines into 2 (max one blank line)
 * - Trims leading/trailing whitespace from each line
 * - Trims leading/trailing whitespace from entire text
 *
 * Usage: normalize_multiline
 *
 * @example "Line 1\t\ttext\r\n\r\n\r\nLine 2" → "Line 1 text\n\nLine 2"
 * @example "  Paragraph 1  \n\n  Paragraph 2  " → "Paragraph 1\n\nParagraph 2"
 */
class NormalizeMultilineTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        // Normalize line endings to \n
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);

        // Replace tabs with single space
        $normalized = str_replace("\t", ' ', $normalized);

        // Process line by line
        $lines = explode("\n", $normalized);
        $lines = array_map(function ($line) {
            // Collapse multiple spaces into single space
            $line = preg_replace('/\s{2,}/', ' ', $line);

            // Trim each line
            return trim($line);
        }, $lines);

        $normalized = implode("\n", $lines);

        // Collapse 3+ consecutive newlines into 2 (keep max one blank line)
        $normalized = preg_replace('/\n{3,}/', "\n\n", $normalized);

        // Trim entire text
        return trim($normalized);
    }

    public function getName(): string
    {
        return 'normalize_multiline';
    }
}
