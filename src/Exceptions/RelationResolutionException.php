<?php

declare(strict_types=1);

namespace InFlow\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when relation resolution fails.
 *
 * Provides context for interactive error handling.
 */
class RelationResolutionException extends RuntimeException
{
    public function __construct(
        public readonly string $modelClass,
        public readonly string $relationName,
        public readonly string $lookupField,
        public readonly mixed $lookupValue,
        public readonly bool $createIfMissing,
        public readonly string $errorType,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Create from a database exception.
     */
    public static function fromDatabaseError(
        Throwable $e,
        string $modelClass,
        string $relationName,
        string $lookupField,
        mixed $lookupValue,
        bool $createIfMissing
    ): self {
        $errorType = self::classifyDatabaseError($e);
        $message = self::buildUserMessage($errorType, $relationName, $lookupField, $lookupValue, $e);

        return new self(
            $modelClass,
            $relationName,
            $lookupField,
            $lookupValue,
            $createIfMissing,
            $errorType,
            $message,
            $e
        );
    }

    /**
     * Classify the database error type.
     */
    private static function classifyDatabaseError(Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'doesn\'t have a default value') ||
            str_contains($message, 'cannot be null')) {
            return 'missing_required';
        }

        if (str_contains($message, 'Duplicate entry') ||
            str_contains($message, 'UNIQUE constraint')) {
            return 'unique_violation';
        }

        if (str_contains($message, 'foreign key constraint') ||
            str_contains($message, 'FOREIGN KEY constraint')) {
            return 'foreign_key';
        }

        if (str_contains($message, 'Data too long') ||
            str_contains($message, 'String data, right truncated')) {
            return 'data_too_long';
        }

        if (str_contains($message, 'Incorrect') ||
            str_contains($message, 'Invalid')) {
            return 'type_mismatch';
        }

        return 'unknown';
    }

    /**
     * Build a user-friendly message.
     */
    private static function buildUserMessage(
        string $errorType,
        string $relationName,
        string $lookupField,
        mixed $lookupValue,
        Throwable $original
    ): string {
        $base = "Cannot resolve relation '{$relationName}' (lookup: {$lookupField}={$lookupValue})";

        return match ($errorType) {
            'missing_required' => "{$base}: The related model requires additional fields that are not available in the source data.",
            'unique_violation' => "{$base}: A record with conflicting unique values already exists.",
            'foreign_key' => "{$base}: Referenced record does not exist.",
            'data_too_long' => "{$base}: One or more values exceed the maximum allowed length.",
            'type_mismatch' => "{$base}: One or more values have an invalid type.",
            default => "{$base}: {$original->getMessage()}",
        };
    }

    /**
     * Get suggested actions based on error type.
     *
     * @return array<string, string>
     */
    public function getSuggestedActions(): array
    {
        return match ($this->errorType) {
            'missing_required' => [
                'skip' => 'Skip this row (don\'t import)',
                'lookup_only' => 'Only lookup existing records, don\'t create new ones',
                'continue' => 'Continue with errors',
            ],
            'unique_violation' => [
                'skip' => 'Skip this row',
                'use_existing' => 'Use the existing related record',
                'continue' => 'Continue with errors',
            ],
            'data_too_long' => [
                'truncate' => 'Truncate values to fit',
                'skip' => 'Skip this row',
                'continue' => 'Continue with errors',
            ],
            default => [
                'skip' => 'Skip this row',
                'continue' => 'Continue with errors',
                'abort' => 'Abort the import',
            ],
        };
    }
}
