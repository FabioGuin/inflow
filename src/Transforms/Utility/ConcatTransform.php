<?php

namespace InFlow\Transforms\Utility;

use InFlow\Contracts\TransformStepInterface;

/**
 * Concatenate multiple fields transformation
 */
class ConcatTransform implements TransformStepInterface
{
    public function __construct(
        private array $fields,
        private string $separator = ' '
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        $parts = [];

        foreach ($this->fields as $field) {
            // If field starts with quote, treat as literal string
            if (str_starts_with($field, '"') && str_ends_with($field, '"')) {
                $parts[] = trim($field, '"');
            } elseif (isset($context[$field])) {
                $parts[] = $context[$field];
            } elseif (isset($context['row']) && is_array($context['row'])) {
                $parts[] = $context['row'][$field] ?? '';
            }
        }

        return implode($this->separator, array_filter($parts, fn ($p) => $p !== null && $p !== ''));
    }

    public function getName(): string
    {
        return 'concat';
    }

    /**
     * Create a ConcatTransform from a string specification (e.g., "concat(fieldA, ' ', fieldB)")
     */
    public static function fromString(string $spec): self
    {
        // Parse: concat(fieldA, " ", fieldB)
        if (! preg_match('/^concat\((.*)\)$/', $spec, $matches)) {
            throw new \InvalidArgumentException("Invalid concat specification: {$spec}");
        }

        $args = self::parseArguments($matches[1]);

        $fields = [];
        $separator = ' ';

        foreach ($args as $arg) {
            $arg = trim($arg);

            if (self::isQuotedString($arg)) {
                $separator = self::stripQuotes($arg);

                continue;
            }

            $fields[] = $arg;
        }

        return new self($fields, $separator);
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

    private static function isQuotedString(string $value): bool
    {
        return (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"));
    }

    private static function stripQuotes(string $value): string
    {
        return trim($value, "\"'");
    }
}
