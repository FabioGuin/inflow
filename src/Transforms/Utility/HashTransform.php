<?php

namespace InFlow\Transforms\Utility;

use Illuminate\Support\Facades\Hash;
use InFlow\Contracts\TransformStepInterface;

/**
 * Hash value transformation (e.g., for passwords)
 */
class HashTransform implements TransformStepInterface
{
    public function __construct(
        private string $algorithm = 'password'
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->algorithm) {
            'password' => Hash::make((string) $value),
            'md5' => md5((string) $value),
            'sha1' => sha1((string) $value),
            'sha256' => hash('sha256', (string) $value),
            default => Hash::make((string) $value), // Default to password hashing
        };
    }

    /**
     * Create HashTransform from string specification (e.g., "hash:password")
     */
    public static function fromString(string $spec): self
    {
        $parts = explode(':', $spec);
        $algorithm = $parts[1] ?? 'password';

        return new self($algorithm);
    }
}
