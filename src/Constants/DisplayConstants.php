<?php

namespace InFlow\Constants;

/**
 * Constants for display and UI purposes.
 *
 * Centralizes technical values like separators, SQL queries, and keyword identifiers.
 */
class DisplayConstants
{
    /**
     * Section separator line.
     */
    public const SECTION_SEPARATOR = '  ──────────────────────────────────────';

    /**
     * Back command keyword for field handler prompts.
     */
    public const BACK_KEYWORD = '__back__';

    /**
     * Maximum number of fields to display in mapping alternatives.
     */
    public const MAX_ALTERNATIVE_FIELDS = 10;

    /**
     * Maximum number of relations to display in mapping alternatives.
     */
    public const MAX_ALTERNATIVE_RELATIONS = 10;

    /**
     * High confidence threshold for mapping suggestions (green).
     * Value is decimal (0.0 - 1.0), not percentage.
     */
    public const CONFIDENCE_THRESHOLD_HIGH = 0.7;

    /**
     * Medium confidence threshold for mapping suggestions (yellow).
     * Value is decimal (0.0 - 1.0), not percentage.
     */
    public const CONFIDENCE_THRESHOLD_MEDIUM = 0.5;
}
