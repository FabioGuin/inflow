<?php

namespace InFlow\Enums\Data;

/**
 * Enum for Eloquent relation types.
 *
 * Represents all supported Eloquent relation types used for model relation discovery.
 */
enum EloquentRelationType: string
{
    case Relation = 'Relation';
    case HasOne = 'HasOne';
    case BelongsTo = 'BelongsTo';
    case HasMany = 'HasMany';
    case BelongsToMany = 'BelongsToMany';
    case MorphTo = 'MorphTo';
    case MorphOne = 'MorphOne';
    case MorphMany = 'MorphMany';

    /**
     * Check if a return type name contains this relation type.
     *
     * @param  string  $returnTypeName  The return type name to check
     * @return bool True if the return type name contains this relation type
     */
    public function matches(string $returnTypeName): bool
    {
        return str_contains($returnTypeName, $this->value);
    }

    /**
     * Check if a return type name represents any Eloquent relation.
     *
     * @param  string  $returnTypeName  The return type name to check
     * @return bool True if it's a relation type
     */
    public static function isRelationType(string $returnTypeName): bool
    {
        foreach (self::cases() as $relationType) {
            if ($relationType->matches($returnTypeName)) {
                return true;
            }
        }

        return false;
    }
}
