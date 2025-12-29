<?php

namespace InFlow\Profilers;

use InFlow\Contracts\ReaderInterface;
use InFlow\Enums\Data\ColumnType;
use InFlow\ValueObjects\Data\ColumnMetadata;
use InFlow\ValueObjects\Data\QualityReport;
use InFlow\ValueObjects\Data\SourceSchema;

/**
 * Profiles data quality and generates schema + quality report
 */
class Profiler
{
    /**
     * Sample size for type detection (first N rows)
     */
    private const SAMPLE_SIZE = 1000;

    /**
     * Maximum examples to store per column
     */
    private const MAX_EXAMPLES = 5;

    /**
     * Profile data from reader and generate schema + quality report
     *
     * @param  ReaderInterface  $reader  The data reader
     * @param  array<string, ColumnType>|null  $typeOverrides  Optional type overrides for specific columns (column name => ColumnType)
     * @return array{schema: SourceSchema, quality_report: QualityReport}
     */
    public function profile(ReaderInterface $reader, ?array $typeOverrides = null): array
    {
        $columns = [];
        $totalRows = 0;
        $warnings = [];
        $errors = [];
        $anomalies = [];

        // Initialize column trackers
        $columnStats = [];
        $columnValues = [];

        // First pass: collect statistics
        foreach ($reader as $row) {
            $totalRows++;

            // Initialize columns dynamically as they appear
            foreach (array_keys($row) as $columnName) {
                if (! isset($columnStats[$columnName])) {
                    $columnStats[$columnName] = [
                        'null_count' => 0,
                        'unique_values' => [],
                        'numeric_values' => [],
                        'date_values' => [],
                        'timestamp_values' => [],
                        'time_values' => [],
                        'bool_values' => [],
                        'string_values' => [],
                        'email_values' => [],
                        'url_values' => [],
                        'phone_values' => [],
                        'ip_values' => [],
                        'uuid_values' => [],
                        'json_values' => [],
                        'examples' => [],
                    ];
                    $columnValues[$columnName] = [];
                }
            }

            // Process each column
            foreach ($row as $columnName => $value) {
                // Normalize value for statistics (convert arrays/objects to JSON strings)
                $normalizedValue = $this->normalizeValue($value);

                // Track null/empty
                if ($this->isEmpty($value)) {
                    $columnStats[$columnName]['null_count']++;
                } else {
                    // Track unique values (limited to avoid memory issues)
                    // Only track scalar values for unique count (skip arrays/objects)
                    if (is_scalar($value) && count($columnStats[$columnName]['unique_values']) < 1000) {
                        $columnStats[$columnName]['unique_values'][$value] = ($columnStats[$columnName]['unique_values'][$value] ?? 0) + 1;
                    }

                    // Track examples (use normalized value for arrays/objects)
                    if (count($columnStats[$columnName]['examples']) < self::MAX_EXAMPLES) {
                        $exampleValue = is_scalar($value) ? $value : $normalizedValue;
                        if (! in_array($exampleValue, $columnStats[$columnName]['examples'], true)) {
                            $columnStats[$columnName]['examples'][] = $exampleValue;
                        }
                    }

                    // Type detection (order matters: specialized types first, then date, numeric, bool, string)
                    if ($this->isEmail($value)) {
                        $columnStats[$columnName]['email_values'][] = $value;
                        $columnStats[$columnName]['string_values'][] = $value; // Also track as string
                    } elseif ($this->isUrl($value)) {
                        $columnStats[$columnName]['url_values'][] = $value;
                        $columnStats[$columnName]['string_values'][] = $value; // Also track as string
                    } elseif ($this->isPhone($value)) {
                        $columnStats[$columnName]['phone_values'][] = $value;
                        $columnStats[$columnName]['string_values'][] = $value; // Also track as string
                    } elseif ($this->isIp($value)) {
                        $columnStats[$columnName]['ip_values'][] = $value;
                        $columnStats[$columnName]['string_values'][] = $value; // Also track as string
                    } elseif ($this->isUuid($value)) {
                        $columnStats[$columnName]['uuid_values'][] = $value;
                        $columnStats[$columnName]['string_values'][] = $value; // Also track as string
                    } elseif ($this->isJson($value)) {
                        $columnStats[$columnName]['json_values'][] = $value;
                        $columnStats[$columnName]['string_values'][] = $value; // Also track as string
                    } elseif ($this->isTimestamp($value)) {
                        $columnStats[$columnName]['timestamp_values'][] = $value;
                        $columnStats[$columnName]['date_values'][] = $value; // Also track as date
                    } elseif ($this->isTime($value)) {
                        $columnStats[$columnName]['time_values'][] = $value;
                    } elseif ($this->isDate($value)) {
                        $columnStats[$columnName]['date_values'][] = $value;
                    } elseif ($this->isBoolean($value)) {
                        // Check boolean before numeric to catch "1"/"0" as boolean
                        // Track as both boolean and potentially numeric (for later analysis)
                        $columnStats[$columnName]['bool_values'][] = $value;
                        // Also track "1"/"0" as numeric for type detection logic
                        if (is_numeric($value)) {
                            $columnStats[$columnName]['numeric_values'][] = $value;
                        }
                    } elseif ($this->isNumeric($value)) {
                        $columnStats[$columnName]['numeric_values'][] = $value;
                    } else {
                        $columnStats[$columnName]['string_values'][] = $value;
                    }
                }

                // Track ALL values for duplicate detection and min/max calculation (including empty)
                $columnValues[$columnName][] = $value;
            }

            // Limit processing for very large files (sample-based)
            if ($totalRows >= self::SAMPLE_SIZE) {
                break;
            }
        }

        // Second pass: detect anomalies (duplicates, invalid dates, etc.)
        if ($totalRows > 0) {
            foreach ($columnValues as $columnName => $values) {
                // Detect duplicates only for columns that should be unique (identifiers)
                if ($this->shouldCheckForDuplicates($columnName, $columnStats[$columnName], $totalRows)) {
                    $scalarValues = array_filter($values, function ($v) {
                        return is_scalar($v) && (is_string($v) || is_int($v));
                    });
                    if (! empty($scalarValues)) {
                        $valueCounts = array_count_values($scalarValues);
                        $duplicateValues = array_filter($valueCounts, fn ($count) => $count > 1);
                        if (! empty($duplicateValues)) {
                            $anomalies[$columnName]['duplicates'] = array_slice($duplicateValues, 0, 10, true);
                            $warnings[] = "Column '{$columnName}' contains duplicate values";
                        }
                    }
                }

                // Detect invalid dates (if column looks like dates)
                $stats = $columnStats[$columnName];
                if (count($stats['date_values']) > count($stats['string_values']) && count($stats['date_values']) > 0) {
                    $invalidDates = [];
                    foreach ($stats['date_values'] as $dateValue) {
                        if (! $this->isValidDate($dateValue)) {
                            $invalidDates[] = $dateValue;
                            if (count($invalidDates) >= 5) {
                                break;
                            }
                        }
                    }
                    if (! empty($invalidDates)) {
                        $anomalies[$columnName]['invalid_dates'] = $invalidDates;
                        $errors[] = "Column '{$columnName}' contains invalid date formats";
                    }
                }
            }
        }

        // Build ColumnMetadata for each column
        foreach ($columnStats as $columnName => $stats) {
            // Apply type override if provided, otherwise detect automatically
            $type = $typeOverrides[$columnName] ?? $this->detectType($stats, $totalRows);
            $min = null;
            $max = null;

            // Calculate min/max for numeric types
            if ($type === ColumnType::Int || $type === ColumnType::Float) {
                // Always use columnValues for min/max calculation (more reliable)
                if (isset($columnValues[$columnName])) {
                    $numericValues = array_filter($columnValues[$columnName], fn ($v) => is_numeric($v) && $v !== '' && ! $this->isEmpty($v));
                    if (! empty($numericValues)) {
                        $convertedValues = array_map(function ($v) use ($type) {
                            return $type === ColumnType::Int ? (int) $v : (float) $v;
                        }, $numericValues);
                        $min = min($convertedValues);
                        $max = max($convertedValues);
                    }
                }
            } elseif ($type === ColumnType::Date) {
                $dateValues = array_filter($stats['date_values'], [$this, 'isValidDate']);
                if (! empty($dateValues)) {
                    $timestamps = array_map(fn ($d) => strtotime($d), $dateValues);
                    $min = date('Y-m-d', min($timestamps));
                    $max = date('Y-m-d', max($timestamps));
                }
            }

            $columns[$columnName] = new ColumnMetadata(
                name: $columnName,
                type: $type,
                nullCount: $stats['null_count'],
                uniqueCount: count($stats['unique_values']),
                min: $min,
                max: $max,
                examples: $stats['examples']
            );
        }

        $schema = new SourceSchema(
            columns: $columns,
            totalRows: $totalRows
        );

        $qualityReport = new QualityReport(
            warnings: $warnings,
            errors: $errors,
            anomalies: $anomalies
        );

        return [
            'schema' => $schema,
            'quality_report' => $qualityReport,
        ];
    }

    /**
     * Detect column type based on statistics
     */
    private function detectType(array $stats, int $totalRows): ColumnType
    {
        $nonNullCount = $totalRows - $stats['null_count'];

        if ($nonNullCount === 0) {
            return ColumnType::String; // Default for empty columns
        }

        $numericCount = count($stats['numeric_values']);
        $dateCount = count($stats['date_values']);
        $timestampCount = count($stats['timestamp_values'] ?? []);
        $timeCount = count($stats['time_values'] ?? []);
        $boolCount = count($stats['bool_values']);
        $emailCount = count($stats['email_values'] ?? []);
        $urlCount = count($stats['url_values'] ?? []);
        $phoneCount = count($stats['phone_values'] ?? []);
        $ipCount = count($stats['ip_values'] ?? []);
        $uuidCount = count($stats['uuid_values'] ?? []);
        $jsonCount = count($stats['json_values'] ?? []);
        $stringCount = count($stats['string_values']);

        $numericRatio = $numericCount / $nonNullCount;
        $dateRatio = $dateCount / $nonNullCount;
        $timestampRatio = $timestampCount / $nonNullCount;
        $timeRatio = $timeCount / $nonNullCount;
        $boolRatio = $boolCount / $nonNullCount;
        $emailRatio = $emailCount / $nonNullCount;
        $urlRatio = $urlCount / $nonNullCount;
        $phoneRatio = $phoneCount / $nonNullCount;
        $ipRatio = $ipCount / $nonNullCount;
        $uuidRatio = $uuidCount / $nonNullCount;
        $jsonRatio = $jsonCount / $nonNullCount;

        // Type detection priority: bool > specialized types > json > timestamp > time > date > decimal > numeric > string
        // Use lower thresholds for better detection, but require minimum counts

        // Boolean: at least 80% boolean values AND at least 2 boolean values
        // OR if column has only "1"/"0" values (common CSV boolean pattern)
        $boolValues = array_unique($stats['bool_values'] ?? []);
        $onlyOneZero = $nonNullCount > 0 &&
                       $boolCount >= 2 &&
                       count($boolValues) <= 2 &&
                       empty(array_diff($boolValues, ['1', '0', 1, 0, 'true', 'false', true, false]));

        if (($boolRatio >= 0.8 && $boolCount >= 2) || $onlyOneZero) {
            return ColumnType::Bool;
        }

        // Specialized string types: at least 70% match AND at least 2 values
        if ($emailRatio >= 0.7 && $emailCount >= 2) {
            return ColumnType::Email;
        }
        if ($urlRatio >= 0.7 && $urlCount >= 2) {
            return ColumnType::Url;
        }
        if ($phoneRatio >= 0.7 && $phoneCount >= 2) {
            return ColumnType::Phone;
        }
        if ($ipRatio >= 0.7 && $ipCount >= 2) {
            return ColumnType::Ip;
        }
        if ($uuidRatio >= 0.7 && $uuidCount >= 2) {
            return ColumnType::Uuid;
        }

        // JSON: at least 70% JSON values AND at least 2 JSON values
        // JSON strings are valid JSON arrays or objects
        if ($jsonRatio >= 0.7 && $jsonCount >= 2) {
            return ColumnType::Json;
        }

        // Timestamp: at least 60% timestamp values AND at least 2 values
        // Timestamp values are also tracked as dates, so we check timestamp ratio first
        if ($timestampRatio >= 0.6 && $timestampCount >= 2) {
            return ColumnType::Timestamp;
        }

        // Time: at least 70% time values AND at least 2 values
        if ($timeRatio >= 0.7 && $timeCount >= 2) {
            return ColumnType::Time;
        }

        // Date: at least 60% date values AND at least 2 date values AND more dates than strings
        if ($dateRatio >= 0.6 && $dateCount >= 2 && $dateCount > $stringCount) {
            return ColumnType::Date;
        }

        // Numeric: at least 60% numeric values AND at least 2 numeric values
        // OR if all non-null values are numeric (even if ratio is lower due to many nulls)
        if (($numericRatio >= 0.6 && $numericCount >= 2) || ($numericCount > 0 && $numericCount === $nonNullCount)) {
            // Check if it's decimal (money-like: 2-4 decimal places, often used for prices)
            $hasDecimal = false;
            $decimalPlaces = 0;
            $sampleSize = min(100, $numericCount);
            foreach (array_slice($stats['numeric_values'], 0, $sampleSize) as $value) {
                if (is_numeric($value)) {
                    $floatVal = (float) $value;
                    $intVal = (int) $floatVal;

                    // Check if it's actually a float (has decimal part)
                    if ($floatVal != $intVal) {
                        $hasDecimal = true;
                        // Count decimal places
                        $parts = explode('.', (string) $value);
                        if (isset($parts[1])) {
                            $decimals = strlen(rtrim($parts[1], '0'));
                            $decimalPlaces = max($decimalPlaces, $decimals);
                        }
                    }
                }
            }

            // If has decimals and typically 2-4 decimal places, it's likely decimal/money
            if ($hasDecimal && $decimalPlaces >= 2 && $decimalPlaces <= 4) {
                return ColumnType::Decimal;
            }

            return $hasDecimal ? ColumnType::Float : ColumnType::Int;
        }

        return ColumnType::String;
    }

    /**
     * Normalize value for statistics (convert arrays/objects to JSON strings)
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    /**
     * Check if value is empty
     */
    private function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return empty($value);
        }

        return $value === null || $value === '' || $value === 'null' || $value === 'NULL';
    }

    /**
     * Check if value is numeric
     * Excludes values that look like dates (contain separators like -, /, .)
     */
    private function isNumeric(mixed $value): bool
    {
        if (is_bool($value)) {
            return false;
        }

        // First check if it looks like a date (dates should be detected before numbers)
        if (is_string($value) && preg_match('/^\d{4}[-\/\.]\d{1,2}[-\/\.]\d{1,2}/', $value)) {
            return false;
        }

        // Then check if it's numeric
        if (! is_numeric($value)) {
            return false;
        }

        // Exclude values that contain date separators (they should be detected as dates)
        // But allow decimal point (.) for floats - only exclude if it looks like a date pattern
        if (is_string($value)) {
            // Check if it's a date pattern (YYYY-MM-DD, MM/DD/YYYY, etc.) - exclude these
            if (preg_match('/^\d{4}[-\/\.]\d{1,2}[-\/\.]\d{1,2}/', $value)) {
                return false;
            }
            // Also exclude if it has date separators AND looks like a date (not just a decimal number)
            if (preg_match('/[-\/]/', $value) && strtotime($value) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if value looks like a date
     */
    private function isDate(mixed $value): bool
    {
        if (! is_string($value) || empty($value)) {
            return false;
        }

        // Exclude pure numbers (they should be detected as numeric, not date)
        if (is_numeric($value) && ! preg_match('/[-\/\.\s]/', $value)) {
            return false;
        }

        // Common date patterns (more comprehensive)
        $datePatterns = [
            '/^\d{4}-\d{2}-\d{2}(?:\s+\d{2}:\d{2}:\d{2})?$/', // YYYY-MM-DD [HH:MM:SS]
            '/^\d{2}\/\d{2}\/\d{4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s+[AP]M)?)?$/i', // MM/DD/YYYY [time]
            '/^\d{2}\.\d{2}\.\d{4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?$/', // DD.MM.YYYY [time]
            '/^\d{1,2}\/\d{1,2}\/\d{4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s+[AP]M)?)?$/i', // M/D/YYYY [time]
            '/^\d{2}-\d{2}-\d{4}$/', // DD-MM-YYYY
            '/^\d{4}\/\d{2}\/\d{2}$/', // YYYY/MM/DD
        ];

        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, trim($value))) {
                // Verify it's actually a valid date
                $timestamp = strtotime($value);
                if ($timestamp !== false && $timestamp > 0) {
                    return true;
                }
            }
        }

        // Try strtotime as fallback (but be more strict)
        $timestamp = strtotime($value);
        if ($timestamp !== false && $timestamp > 0) {
            // Additional check: make sure it's not just a number
            if (! is_numeric($value) || preg_match('/[-\/\.\s]/', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if value is boolean
     * Supports: true/false, yes/no, y/n, and "1"/"0" (when used as boolean)
     */
    private function isBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        $lower = strtolower(trim($value));

        return in_array($lower, ['true', 'false', 'yes', 'no', 'y', 'n', '1', '0'], true);
    }

    /**
     * Check if date string is valid
     */
    private function isValidDate(mixed $value): bool
    {
        if (! is_string($value) || empty($value)) {
            return false;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false;
    }

    /**
     * Check if value is an email address using regex
     */
    private function isEmail(mixed $value): bool
    {
        if (! is_string($value) || empty($value)) {
            return false;
        }

        // RFC 5322 compliant email regex (simplified but effective)
        return (bool) preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', trim($value));
    }

    /**
     * Check if value is a URL using regex
     */
    private function isUrl(mixed $value): bool
    {
        if (! is_string($value) || empty($value)) {
            return false;
        }

        $value = trim($value);

        // Check for common URL patterns
        $urlPatterns = [
            '/^https?:\/\/.+/i', // http:// or https://
            '/^www\..+/i', // www.example.com
            '/^[a-zA-Z0-9][a-zA-Z0-9-]{1,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}\/.+/i', // domain.com/path
        ];

        foreach ($urlPatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        // Also check with filter_var for more strict validation
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Check if value is a phone number using regex
     */
    private function isPhone(mixed $value): bool
    {
        if (! is_string($value) || empty($value)) {
            return false;
        }

        $value = trim($value);

        // Remove common phone number separators for pattern matching
        $normalized = preg_replace('/[\s\-\(\)\.]/', '', $value);

        // Phone number patterns (international and local formats)
        $phonePatterns = [
            '/^\+?[1-9]\d{1,14}$/', // E.164 international format
            '/^\d{10,15}$/', // 10-15 digits (common phone number length)
            '/^\+?\d{1,3}[\s\-]?\d{1,4}[\s\-]?\d{1,4}[\s\-]?\d{1,9}$/', // Formatted international
        ];

        // Check if normalized value matches patterns
        foreach ($phonePatterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                // Additional check: should have at least 10 digits
                $digitCount = preg_match_all('/\d/', $value);
                if ($digitCount >= 10 && $digitCount <= 15) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if value is an IP address using regex
     */
    private function isIp(mixed $value): bool
    {
        if (! is_string($value) || empty($value)) {
            return false;
        }

        $value = trim($value);

        // IPv4 pattern
        $ipv4Pattern = '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/';
        if (preg_match($ipv4Pattern, $value)) {
            return true;
        }

        // IPv6 pattern (simplified)
        $ipv6Pattern = '/^(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$|^::1$|^::$/';
        if (preg_match($ipv6Pattern, $value)) {
            return true;
        }

        // Also check with filter_var for more strict validation
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Check if value is a UUID using regex
     */
    private function isUuid(mixed $value): bool
    {
        if (! is_string($value) || empty($value)) {
            return false;
        }

        $value = trim($value);

        // UUID pattern (supports v1, v4, and other versions)
        // Format: 8-4-4-4-12 hexadecimal digits separated by hyphens
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        return (bool) preg_match($uuidPattern, $value);
    }

    /**
     * Check if value is a valid JSON string (array or object)
     */
    private function isJson(mixed $value): bool
    {
        if (! is_string($value) || empty($value)) {
            return false;
        }

        $trimmed = trim($value);

        // Must start with [ (array) or { (object)
        if (! (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{'))) {
            return false;
        }

        // Try to decode and verify it's valid JSON
        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        // Must decode to array or object (not scalar)
        return is_array($decoded) || is_object($decoded);
    }

    /**
     * Check if value is a timestamp (datetime with time component)
     */
    private function isTimestamp(mixed $value): bool
    {
        if (! is_string($value) || empty($value)) {
            return false;
        }

        $trimmed = trim($value);

        // Timestamp patterns (date + time)
        $timestampPatterns = [
            '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/', // YYYY-MM-DD HH:MM:SS
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', // YYYY-MM-DDTHH:MM:SS (ISO)
            '/^\d{2}\/\d{2}\/\d{4}\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s+[AP]M)?$/i', // MM/DD/YYYY HH:MM:SS [AM/PM]
            '/^\d{2}\.\d{2}\.\d{4}\s+\d{1,2}:\d{2}(?::\d{2})?$/', // DD.MM.YYYY HH:MM:SS
        ];

        foreach ($timestampPatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                $timestamp = strtotime($trimmed);
                if ($timestamp !== false && $timestamp > 0) {
                    return true;
                }
            }
        }

        // Try strtotime as fallback
        $timestamp = strtotime($trimmed);
        if ($timestamp !== false && $timestamp > 0) {
            // Must contain both date and time separators/indicators
            if (preg_match('/[-\/\.]/', $trimmed) && preg_match('/[:\s]/', $trimmed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if value is a time (only time, no date)
     */
    private function isTime(mixed $value): bool
    {
        if (! is_string($value) || empty($value)) {
            return false;
        }

        $trimmed = trim($value);

        // Time patterns (no date component)
        $timePatterns = [
            '/^\d{1,2}:\d{2}(?::\d{2})?(?:\s+[AP]M)?$/i', // HH:MM:SS [AM/PM]
            '/^\d{2}:\d{2}:\d{2}$/', // HH:MM:SS (24h)
            '/^\d{1,2}:\d{2}$/', // HH:MM
        ];

        foreach ($timePatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                // Verify it's not a date by checking it doesn't have date separators
                if (! preg_match('/[-\/\.]/', $trimmed)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if a column should be checked for duplicates.
     *
     * Only columns that look like unique identifiers should trigger duplicate warnings.
     * Boolean columns, status columns, low-cardinality columns, and foreign keys are excluded.
     *
     * @param  string  $columnName  The column name
     * @param  array  $stats  The column statistics
     * @param  int  $totalRows  Total number of rows
     */
    private function shouldCheckForDuplicates(string $columnName, array $stats, int $totalRows): bool
    {
        $lowerName = strtolower($columnName);

        // Skip foreign keys (columns ending with _id except 'id' itself)
        // Foreign keys can have duplicates as they reference the same related record
        if (str_ends_with($lowerName, '_id') && $lowerName !== 'id') {
            return false;
        }

        // Skip boolean columns (duplicates are expected)
        $boolCount = count($stats['bool_values']);
        $nonNullCount = $totalRows - $stats['null_count'];
        if ($nonNullCount > 0 && ($boolCount / $nonNullCount) >= 0.5) {
            return false;
        }

        // Skip low-cardinality columns (< 10% unique values) - likely categories/statuses
        $uniqueCount = count($stats['unique_values']);
        if ($nonNullCount > 10 && $uniqueCount > 0 && ($uniqueCount / $nonNullCount) < 0.1) {
            return false;
        }

        // Skip columns with very few distinct values (≤ 5) unless they look like identifiers
        if ($uniqueCount <= 5 && ! $this->looksLikeIdentifier($lowerName)) {
            return false;
        }

        // Only check columns that look like identifiers
        return $this->looksLikeIdentifier($lowerName);
    }

    /**
     * Check if column name looks like a unique identifier.
     *
     * @param  string  $lowerName  The column name in lowercase
     */
    private function looksLikeIdentifier(string $lowerName): bool
    {
        // Patterns that suggest unique identifiers
        $identifierPatterns = [
            'id', 'code', 'sku', 'email', 'username', 'slug',
            'identifier', 'key', 'ref', 'number', 'uuid', 'guid',
            'barcode', 'serial', 'external', 'unique',
        ];

        foreach ($identifierPatterns as $pattern) {
            if (str_contains($lowerName, $pattern)) {
                return true;
            }
        }

        // Exact matches for common identifier columns
        $exactMatches = ['ean', 'isbn', 'vin', 'ssn', 'nif', 'vat'];

        return in_array($lowerName, $exactMatches, true);
    }
}
