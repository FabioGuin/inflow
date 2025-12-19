<?php

namespace InFlow\Transforms\Contracts;

/**
 * Interface for transforms that require interactive parameter input.
 *
 * Transforms implementing this interface define their own prompts,
 * keeping the interaction logic close to the transform that needs it.
 */
interface InteractiveTransformInterface
{
    /**
     * Get parameter prompts for interactive configuration.
     *
     * Each prompt is an array with:
     * - 'label': The question to ask (required)
     * - 'hint': Additional context (optional)
     * - 'examples': Example values (optional)
     * - 'default': Default value (optional)
     *
     * @return array<array{label: string, hint?: string, examples?: string[], default?: string}>
     */
    public static function getPrompts(): array;

    /**
     * Build the transform specification from user responses.
     *
     * @param  array<string>  $responses  User responses in order of prompts
     * @return string|null The transform specification (e.g., "parse_date:d/m/Y") or null if cancelled
     */
    public static function buildSpec(array $responses): ?string;
}
