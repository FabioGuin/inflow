<?php

namespace InFlow\Readers;

use InFlow\Contracts\ReaderInterface;
use InFlow\Sources\FileSource;
use SimpleXMLElement;

/**
 * XML reader that converts hierarchical XML to tabular rows.
 *
 * Auto-detects the repeating element (e.g., <author>) and flattens
 * child elements as columns (e.g., personal_info.full_name).
 *
 * Uses generators for memory-efficient processing of large XML files.
 */
class XmlReader implements ReaderInterface
{
    private ?\Generator $rowGenerator = null;

    private ?array $currentRow = null;

    private int $currentRowIndex = -1;

    private ?string $rowElementName = null;

    private ?SimpleXMLElement $xml = null;

    private bool $initialized = false;

    public function __construct(
        private readonly FileSource $source
    ) {}

    /**
     * Initialize reader and parse XML file.
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $filePath = $this->source->getPath();

        if (! file_exists($filePath)) {
            throw new \RuntimeException("XML file not found: {$filePath}");
        }

        libxml_use_internal_errors(true);
        $this->xml = simplexml_load_file($filePath);

        if ($this->xml === false) {
            $errors = libxml_get_errors();
            $errorMsg = $errors[0]->message ?? 'Unknown XML parsing error';
            libxml_clear_errors();

            throw new \RuntimeException("Failed to parse XML: {$errorMsg}");
        }

        // Auto-detect repeating element (first child of root)
        $this->rowElementName = $this->detectRowElement($this->xml);

        if ($this->rowElementName === null) {
            throw new \RuntimeException('Could not detect repeating element in XML');
        }

        // Create generator for rows
        $this->rowGenerator = $this->generateRows($this->xml);

        $this->initialized = true;
    }

    /**
     * Detect the repeating element name (e.g., "author" in <authors><author>...</author></authors>).
     */
    private function detectRowElement(SimpleXMLElement $xml): ?string
    {
        $children = $xml->children();

        if (count($children) === 0) {
            return null;
        }

        // Get first child element name
        $firstChild = $children[0];
        $elementName = $firstChild->getName();

        // Verify it repeats (check if there are multiple with same name)
        $count = count($xml->{$elementName});

        return $count > 1 ? $elementName : null;
    }

    /**
     * Generate rows from XML elements using generator for memory efficiency.
     *
     * Handles repeating child elements by creating multiple rows.
     * For example, if <author> has <publications><book> (multiple), creates one row per book.
     *
     * @return \Generator<array<string, mixed>>
     */
    private function generateRows(SimpleXMLElement $xml): \Generator
    {
        if ($this->rowElementName === null) {
            return;
        }

        foreach ($xml->{$this->rowElementName} as $element) {
            // Check if this element has repeating children
            $repeatingChildren = $this->detectRepeatingChildren($element);

            if (empty($repeatingChildren)) {
                // No repeating children - single row
                $row = $this->convertXmlElementToFlatArray($element);
                yield $row;
            } else {
                // Has repeating children - yield one row per repetition
                foreach ($this->expandRepeatingChildrenIntoRowsGenerator($element, $repeatingChildren) as $expandedRow) {
                    yield $expandedRow;
                }
            }
        }
    }

    /**
     * Detect repeating child elements (same name appears multiple times).
     *
     * @return array<string> Array of child element names that repeat
     */
    private function detectRepeatingChildren(SimpleXMLElement $element): array
    {
        $repeating = [];
        $childCounts = [];

        foreach ($element->children() as $child) {
            $name = $child->getName();
            $childCounts[$name] = ($childCounts[$name] ?? 0) + 1;
        }

        // Find children that appear more than once
        foreach ($childCounts as $name => $count) {
            if ($count > 1) {
                $repeating[] = $name;
            }
        }

        return $repeating;
    }

    /**
     * Expand element with repeating children into multiple rows using generator.
     *
     * For each repetition of the repeating element, yields a row with:
     * - All non-repeating children (flattened once)
     * - The current repetition of the repeating element (flattened)
     *
     * @param  array<string>  $repeatingChildNames  Names of repeating child elements
     * @return \Generator<array<string, mixed>> Generator of rows
     */
    private function expandRepeatingChildrenIntoRowsGenerator(SimpleXMLElement $element, array $repeatingChildNames): \Generator
    {
        // Get base data (non-repeating children)
        $baseData = [];
        foreach ($element->children() as $child) {
            $name = $child->getName();
            if (! in_array($name, $repeatingChildNames, true)) {
                // Non-repeating - flatten once and add to base
                $flattened = $this->convertXmlElementToFlatArray($child, $name);
                $baseData = array_merge($baseData, $flattened);
            }
        }

        // Get the first repeating element to determine count
        $firstRepeatingName = $repeatingChildNames[0];
        $repeatingCount = count($element->{$firstRepeatingName});

        // Yield one row per repetition
        for ($i = 0; $i < $repeatingCount; $i++) {
            $row = $baseData;

            // Add all repeating children for this iteration
            foreach ($repeatingChildNames as $repeatingName) {
                $repeatingElements = $element->{$repeatingName};
                if (isset($repeatingElements[$i])) {
                    $flattened = $this->convertXmlElementToFlatArray($repeatingElements[$i], $repeatingName);
                    $row = array_merge($row, $flattened);
                }
            }

            yield $row;
        }
    }

    /**
     * Convert an XML element to a flat associative array.
     *
     * Handles nested elements by using dot notation (e.g., "personal_info.full_name").
     * Supports up to 2 levels of nesting for simplicity.
     * When multiple elements with the same name exist, creates arrays for them.
     *
     * @return array<string, mixed>
     */
    private function convertXmlElementToFlatArray(SimpleXMLElement $element, string $prefix = ''): array
    {
        $result = [];

        // Get attributes
        $attributes = (array) $element->attributes();
        if (! empty($attributes['@attributes'])) {
            foreach ($attributes['@attributes'] as $attrName => $attrValue) {
                $key = $prefix ? "{$prefix}.{$attrName}" : $attrName;
                $result[$key] = (string) $attrValue;
            }
        }

        // Get child elements
        $children = $element->children();

        if (count($children) === 0) {
            // Leaf node - get text content
            $text = trim((string) $element);
            if ($text !== '') {
                $key = $prefix ?: $element->getName();
                $result[$key] = $text;
            }
        } else {
            // Count children by name to detect repeating elements
            $childCounts = [];
            foreach ($children as $child) {
                $childName = $child->getName();
                $childCounts[$childName] = ($childCounts[$childName] ?? 0) + 1;
            }

            // Process children
            foreach ($children as $child) {
                $childName = $child->getName();
                $childPrefix = $prefix ? "{$prefix}.{$childName}" : $childName;

                // Check if this child has further children
                $grandchildren = $child->children();

                if (count($grandchildren) === 0) {
                    // Leaf - add directly
                    $text = trim((string) $child);
                    if ($text !== '') {
                        // If multiple children with same name, create array
                        if ($childCounts[$childName] > 1) {
                            if (! isset($result[$childPrefix]) || ! is_array($result[$childPrefix])) {
                                $result[$childPrefix] = [];
                            }
                            $result[$childPrefix][] = $text;
                        } else {
                            $result[$childPrefix] = $text;
                        }
                    }
                } else {
                    // Has grandchildren - flatten recursively
                    $nested = $this->convertXmlElementToFlatArray($child, $childPrefix);

                    // If multiple children with same name, collect them as array
                    if ($childCounts[$childName] > 1) {
                        // Initialize array if first occurrence
                        if (! isset($result[$childPrefix])) {
                            $result[$childPrefix] = [];
                        }

                        // Remove prefix from nested keys to create clean array elements
                        $cleanNested = [];
                        foreach ($nested as $nestedKey => $nestedValue) {
                            // Remove the prefix (e.g., "tags.tag.name" -> "name")
                            $cleanKey = str_replace($childPrefix.'.', '', $nestedKey);
                            $cleanNested[$cleanKey] = $nestedValue;
                        }

                        // Add nested data as array element
                        $result[$childPrefix][] = $cleanNested;
                    } else {
                        // Single child - merge normally
                        $result = array_merge($result, $nested);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get current row as associative array.
     */
    public function current(): array
    {
        $this->initialize();

        return $this->currentRow ?? [];
    }

    /**
     * Move to next row.
     */
    public function next(): void
    {
        $this->initialize();

        if ($this->rowGenerator === null) {
            $this->currentRow = null;

            return;
        }

        if ($this->rowGenerator->valid()) {
            $this->currentRow = $this->rowGenerator->current();
            $this->rowGenerator->next();
            $this->currentRowIndex++;
        } else {
            $this->currentRow = null;
        }
    }

    /**
     * Get current key (row index).
     */
    public function key(): int
    {
        return $this->currentRowIndex;
    }

    /**
     * Check if current position is valid.
     */
    public function valid(): bool
    {
        $this->initialize();

        return $this->currentRow !== null;
    }

    /**
     * Rewind to beginning.
     */
    public function rewind(): void
    {
        $this->currentRowIndex = -1;
        $this->currentRow = null;
        $this->rowGenerator = null;
        $this->initialized = false;
        $this->initialize();
        $this->next();
    }

    /**
     * Get column headers (keys from first row).
     *
     * @return array<string>|null Array of column names, or null if no rows
     */
    public function getHeaders(): ?array
    {
        $this->initialize();

        if ($this->rowGenerator === null) {
            return null;
        }

        // Save current position
        $savedIndex = $this->currentRowIndex;
        $savedRow = $this->currentRow;
        $savedGenerator = $this->rowGenerator;

        // Rewind and get first row
        $this->rewind();
        $firstRow = $this->current();

        // Restore position
        $this->currentRowIndex = $savedIndex;
        $this->currentRow = $savedRow;
        $this->rowGenerator = $savedGenerator;

        if (empty($firstRow)) {
            return null;
        }

        return array_keys($firstRow);
    }
}
