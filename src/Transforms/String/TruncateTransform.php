<?php

namespace InFlow\Transforms\String;

use Illuminate\Support\Str;
use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Truncate string to N characters
 *
 * Usage: truncate:100 or truncate:100:...
 */
readonly class TruncateTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private int    $length = 255,
        private string $end = '...'
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return Str::limit($value, $this->length, $this->end);
    }

    public function getName(): string
    {
        return 'truncate';
    }

    public static function fromString(string $definition): self
    {
        $parts = explode(':', $definition, 3);
        $length = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 255;
        $end = $parts[2] ?? '...';

        return new self($length, $end);
    }

    public static function getPrompts(): array
    {
        return [
            [
                'label' => 'Enter max length',
                'hint' => 'Maximum number of characters',
                'examples' => ['50', '100', '255'],
                'default' => '255',
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $length = $responses[0] ?? null;

        return (is_numeric($length)) ? "truncate:{$length}" : null;
    }
}
