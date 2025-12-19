<?php

namespace InFlow\Transforms\Utility;

use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Use fallback value if value is null or empty
 *
 * Usage: coalesce:N/A or coalesce:0
 */
readonly class CoalesceTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private string $fallback = ''
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null || $value === '') {
            return $this->fallback;
        }

        if (is_string($value) && trim($value) === '') {
            return $this->fallback;
        }

        return $value;
    }

    public function getName(): string
    {
        return 'coalesce';
    }

    public static function fromString(string $definition): self
    {
        $parts = explode(':', $definition, 2);

        return new self($parts[1] ?? '');
    }

    public static function getPrompts(): array
    {
        return [
            [
                'label' => 'Enter fallback value',
                'hint' => 'Value to use when field is empty/null',
                'examples' => ['N/A', '0', 'unknown', '-'],
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $fallback = $responses[0] ?? null;

        return $fallback !== null ? "coalesce:{$fallback}" : null;
    }
}
