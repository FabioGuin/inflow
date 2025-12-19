<?php

namespace InFlow\Transforms\Utility;

use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Regex replace transformation
 */
readonly class RegexReplaceTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private string $pattern,
        private string $replacement
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_replace($this->pattern, $this->replacement, $value);
    }

    public function getName(): string
    {
        return 'regex_replace';
    }

    /**
     * Create a RegexReplaceTransform from a string specification (e.g., "regex_replace(/pattern/, replacement)")
     */
    public static function fromString(string $spec): self
    {
        // Parse: regex_replace(/pattern/, replacement)
        if (! preg_match('/^regex_replace\((.+)\)$/', $spec, $matches)) {
            throw new \InvalidArgumentException("Invalid regex_replace specification: {$spec}");
        }

        $args = self::parseArguments($matches[1]);

        if (count($args) < 2) {
            throw new \InvalidArgumentException("regex_replace requires pattern and replacement: {$spec}");
        }

        $pattern = self::normalizePattern(trim($args[0]));
        $replacement = self::stripQuotes(trim($args[1]));

        return new self($pattern, $replacement);
    }

    /**
     * Parse comma-separated arguments, respecting both single and double quotes.
     *
     * @return array<int, string>
     */
    private static function parseArguments(string $args): array
    {
        $result = [];
        $buffer = '';
        $quote = null;
        $length = strlen($args);

        for ($i = 0; $i < $length; $i++) {
            $char = $args[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                    $buffer .= $char;

                    continue;
                }

                $buffer .= $char;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;

                continue;
            }

            if ($char === ',') {
                $result[] = trim($buffer);
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $result[] = trim($buffer);
        }

        return $result;
    }

    private static function stripQuotes(string $value): string
    {
        return trim($value, "\"' \t\n\r\0\x0B");
    }

    /**
     * Normalize JS-style regex literals to PHP PCRE patterns.
     *
     * Examples:
     * - `/\s+/g` => `/\s+/`
     * - `/abc/i` => `/abc/i`
     */
    private static function normalizePattern(string $pattern): string
    {
        $pattern = trim($pattern);

        if (preg_match('#^/(.*)/([a-zA-Z]*)$#', $pattern, $matches)) {
            $body = $matches[1];
            $flags = $matches[2] ?? '';

            // "g" (global) is implicit in preg_replace
            $flags = str_replace(['g', 'G'], '', $flags);

            return '/'.$body.'/'.$flags;
        }

        return $pattern;
    }

    public static function getPrompts(): array
    {
        return [
            [
                'label' => 'Enter regex pattern',
                'hint' => 'PCRE pattern with delimiters',
                'examples' => ['/\s+/ (whitespace)', '/[^a-z]/i (non-letters)', '/\d+/ (digits)'],
            ],
            [
                'label' => 'Enter replacement',
                'hint' => 'Text to replace matches with (use $1, $2 for captured groups)',
                'examples' => ['" " (space)', '"-" (dash)', '"" (remove)'],
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $pattern = $responses[0] ?? null;
        $replacement = $responses[1] ?? '';

        if ($pattern === null || $pattern === '') {
            return null;
        }

        return "regex_replace({$pattern},{$replacement})";
    }
}
