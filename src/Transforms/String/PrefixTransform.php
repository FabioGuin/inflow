<?php

namespace InFlow\Transforms\String;

use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Add prefix to string
 *
 * Usage: prefix:PRE_
 */
readonly class PrefixTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private string $prefix = ''
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $value;
        }

        return $this->prefix. $value;
    }

    public function getName(): string
    {
        return 'prefix';
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
                'label' => 'Enter prefix text',
                'hint' => 'Text to add before the value',
                'examples' => ['SKU-', 'ID_', 'PREFIX_'],
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $prefix = $responses[0] ?? null;

        return ($prefix !== null && $prefix !== '') ? "prefix:{$prefix}" : null;
    }
}
