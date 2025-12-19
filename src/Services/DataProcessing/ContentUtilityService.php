<?php

namespace InFlow\Services\DataProcessing;

/**
 * Service for content utility operations.
 *
 * Provides utility methods for content manipulation and analysis.
 */
class ContentUtilityService
{
    /**
     * Count lines in content.
     *
     * Handles different newline formats: LF, CRLF, and CR.
     *
     * @param  string  $content  The content to count lines in
     * @return int The number of lines
     */
    public function countLines(string $content): int
    {
        if (empty($content)) {
            return 0;
        }

        // Count newlines (handles LF, CRLF, CR)
        return substr_count($content, "\n") + substr_count($content, "\r") - substr_count($content, "\r\n") + 1;
    }
}
