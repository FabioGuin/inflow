<?php

namespace InFlow\Transforms\Utility;

use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

/**
 * Default value transformation
 */
readonly class DefaultTransform implements InteractiveTransformInterface, TransformStepInterface
{
    public function __construct(
        private mixed $defaultValue
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null || $value === '') {
            return $this->defaultValue;
        }

        return $value;
    }

    public function getName(): string
    {
        return 'default';
    }

    /**
     * Create a DefaultTransform from a string specification (e.g., "default:value")
     */
    public static function fromString(string $spec): self
    {
        if (! str_starts_with($spec, 'default:')) {
            throw new \InvalidArgumentException("Invalid default specification: {$spec}");
        }

        $value = substr($spec, 8);

        return new self($value);
    }

    public static function getPrompts(): array
    {
        return [
            [
                'label' => 'Enter default value',
                'hint' => 'This value will be used when the field is empty or null',
                'examples' => ['0', 'N/A', 'unknown'],
            ],
        ];
    }

    public static function buildSpec(array $responses): ?string
    {
        $value = $responses[0] ?? null;

        return ($value !== null && $value !== '') ? "default:{$value}" : null;
    }
}
