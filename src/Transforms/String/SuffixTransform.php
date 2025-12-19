<?php

namespace InFlow\Transforms\String;

use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Add suffix to string
 *
 * Usage: suffix:_END
 */
readonly class SuffixTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private string $suffix = ''
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $value;
        }

        return $value .$this->suffix;
    }

    public function getName(): string
    {
        return 'suffix';
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
                'label' => 'Enter suffix text',
                'hint' => 'Text to add after the value',
                'examples' => ['_v2', '-copy', '_backup'],
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $suffix = $responses[0] ?? null;

        return ($suffix !== null && $suffix !== '') ? "suffix:{$suffix}" : null;
    }
}
