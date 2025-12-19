<?php

namespace InFlow\Transforms\Utility;

use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Split string into array by delimiter
 *
 * Usage: split:, or split:|
 */
readonly class SplitTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private string $delimiter = ','
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return array_map('trim', explode($this->delimiter, $value));
    }

    public function getName(): string
    {
        return 'split';
    }

    public static function fromString(string $definition): self
    {
        $parts = explode(':', $definition, 2);

        return new self($parts[1] ?? ',');
    }

    public static function getPrompts(): array
    {
        return [
            [
                'label' => 'Enter delimiter',
                'hint' => 'Character(s) to split on',
                'examples' => [', (comma)', '| (pipe)', '; (semicolon)', '\\t (tab)'],
                'default' => ',',
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $delimiter = $responses[0] ?? ',';

        return "split:{$delimiter}";
    }
}
