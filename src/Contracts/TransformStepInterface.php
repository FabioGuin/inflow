<?php

namespace InFlow\Contracts;

/**
 * Interface for transformation steps
 */
interface TransformStepInterface
{
    /**
     * Apply the transformation to a value
     *
     * @param  array<string, mixed>  $context
     */
    public function transform(mixed $value, array $context = []): mixed;

    /**
     * Get the name of the transformation
     */
    public function getName(): string;
}
