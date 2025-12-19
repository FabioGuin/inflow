<?php

namespace InFlow\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateTestDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inflow:generate-test-data
                            {model : Model to generate data for (Author, Book, Profile, Tag)}
                            {--rows=100 : Number of rows to generate}
                            {--format=csv : File format (csv, json, xml)}
                            {--output= : Output file path}
                            {--dirty-level=0 : Data quality level (0=clean, 1=light, 2=medium, 3=heavy)}
                            {--with-relations : Include related data (e.g., books with authors, profiles with authors)}
                            {--relations-count= : Number of related records per parent (e.g., books per author)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate realistic test data files for InFlow models (Author, Book, Profile, Tag)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $model = ucfirst(strtolower($this->argument('model')));
        $numRows = (int) $this->option('rows');
        $format = strtolower($this->option('format'));
        $outputPath = $this->option('output');
        $dirtyLevel = (int) $this->option('dirty-level');
        $withRelations = $this->option('with-relations');
        $relationsCount = $this->option('relations-count') ? (int) $this->option('relations-count') : null;

        // Validate model
        $validModels = ['Author', 'Book', 'Profile', 'Tag'];
        if (! in_array($model, $validModels, true)) {
            $this->error("Invalid model. Must be one of: ".implode(', ', $validModels));

            return Command::FAILURE;
        }

        // Validate format
        if (! in_array($format, ['csv', 'json', 'xml'], true)) {
            $this->error('Format must be one of: csv, json, xml');

            return Command::FAILURE;
        }

        // Validate dirty level
        if ($dirtyLevel < 0 || $dirtyLevel > 3) {
            $this->error('Dirty level must be between 0 (clean) and 3 (heavy)');

            return Command::FAILURE;
        }

        if ($numRows < 1) {
            $this->error('Number of rows must be at least 1');

            return Command::FAILURE;
        }

        // Determine output path
        if ($outputPath === null) {
            $rowsLabel = $numRows >= 1000 ? ($numRows / 1000).'k' : $numRows;
            $dirtyLabel = $dirtyLevel > 0 ? "_dirty{$dirtyLevel}" : '';
            $outputPath = storage_path("inflow/test_data/{$model}_{$rowsLabel}{$dirtyLabel}.{$format}");
        } else {
            if (! str_starts_with($outputPath, '/')) {
                $outputPath = storage_path("inflow/test_data/{$outputPath}");
            }
        }

        // Ensure directory exists
        $directory = dirname($outputPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $dirtyInfo = $this->getDirtyLevelInfo($dirtyLevel);

        $infoMsg = "Generating {$format} file for {$model} with {$numRows} rows";
        if ($dirtyLevel > 0) {
            $infoMsg .= " (dirty level {$dirtyLevel}: ".implode(', ', $dirtyInfo).')';
        }
        if ($withRelations) {
            $infoMsg .= ' (with relations)';
        }
        $infoMsg .= '...';
        $this->info($infoMsg);

        $start = microtime(true);

        try {
            match ($format) {
                'csv' => $this->generateCsv($outputPath, $model, $numRows, $dirtyLevel, $withRelations, $relationsCount),
                'json' => $this->generateJson($outputPath, $model, $numRows, $dirtyLevel, $withRelations, $relationsCount),
                'xml' => $this->generateXml($outputPath, $model, $numRows, $dirtyLevel, $withRelations, $relationsCount),
            };
        } catch (\Exception $e) {
            $this->error("Error generating file: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $duration = round(microtime(true) - $start, 2);
        $fileSize = filesize($outputPath);

        $this->newLine();
        $this->info('✓ Test data file generated successfully!');
        $this->line("  File: {$outputPath}");
        $this->line('  Rows: '.number_format($numRows));
        $this->line('  Size: '.number_format($fileSize / 1024, 2).' KB');
        $this->line("  Time: {$duration}s");

        return Command::SUCCESS;
    }

    /**
     * Get dirty level information
     */
    private function getDirtyLevelInfo(int $level): array
    {
        return match ($level) {
            0 => [],
            1 => ['trailing spaces', 'leading spaces'],
            2 => ['trailing spaces', 'leading spaces', 'control chars', '5% duplicates', '5% empty values'],
            3 => ['trailing spaces', 'leading spaces', 'control chars', 'BOM', '10% duplicates', '10% empty values', '10% invalid data', 'mixed newlines'],
            default => [],
        };
    }

    /**
     * Generate CSV file
     */
    private function generateCsv(string $outputPath, string $model, int $numRows, int $dirtyLevel, bool $withRelations, ?int $relationsCount): void
    {
        $handle = fopen($outputPath, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Failed to open file: {$outputPath}");
        }

        // Add BOM if dirty level 3
        if ($dirtyLevel >= 3) {
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
        }

        $config = $this->getModelConfig($model, $withRelations, $relationsCount);
        
        // Expand columns for CSV (flatten profile fields, keep arrays as JSON columns)
        $columns = $this->expandColumnsForCsv($config['columns'], $model, $withRelations);

        // Write header
        if ($dirtyLevel >= 2) {
            $columns[0] = "\x00".$columns[0]; // Null byte at start
        }
        fputcsv($handle, $columns, ',', '"', '\\');

        $progressBar = $this->output->createProgressBar($numRows);
        $progressBar->start();

        $duplicateRows = [];
        $generatedRows = 0;

        for ($i = 1; $i <= $numRows; $i++) {
            // Handle empty rows (dirty level 2+)
            if ($dirtyLevel >= 2 && rand(1, 100) <= ($dirtyLevel * 5)) {
                $newline = $this->getNewline($dirtyLevel >= 3);
                fwrite($handle, $newline);
                $generatedRows++;
                $progressBar->advance();

                continue;
            }

            // Handle duplicates (dirty level 2+)
            $isDuplicate = false;
            if ($dirtyLevel >= 2 && rand(1, 100) <= ($dirtyLevel * 5)) {
                if (! empty($duplicateRows)) {
                    $row = $duplicateRows[array_rand($duplicateRows)];
                    $isDuplicate = true;
                } else {
                    $row = $this->generateRow($i, $model, $config, $dirtyLevel, $withRelations, $relationsCount);
                    $duplicateRows[] = $row;
                }
            } else {
                $row = $this->generateRow($i, $model, $config, $dirtyLevel, $withRelations, $relationsCount);
                if (rand(1, 10) === 1 && count($duplicateRows) < 100) {
                    $duplicateRows[] = $row;
                }
            }

            // Add dirty data
            if ($dirtyLevel > 0) {
                $row = $this->addDirtyData($row, $dirtyLevel);
            }

            // Add invalid data (dirty level 3)
            if ($dirtyLevel >= 3 && rand(1, 100) <= 10) {
                $row = $this->addInvalidData($row, $model, $columns);
            }

            // Format and write row
            $rowString = $this->formatCsvRow($row);
            $newline = $this->getNewline($dirtyLevel >= 3);
            fwrite($handle, $rowString.$newline);

            $generatedRows++;
            $progressBar->advance();
        }

        $progressBar->finish();
        fclose($handle);
    }

    /**
     * Generate JSON file
     */
    private function generateJson(string $outputPath, string $model, int $numRows, int $dirtyLevel, bool $withRelations, ?int $relationsCount): void
    {
        $config = $this->getModelConfig($model, $withRelations, $relationsCount);
        $data = [];

        $progressBar = $this->output->createProgressBar($numRows);
        $progressBar->start();

        $duplicateRows = [];

        for ($i = 1; $i <= $numRows; $i++) {
            // Handle empty rows (dirty level 2+)
            if ($dirtyLevel >= 2 && rand(1, 100) <= ($dirtyLevel * 5)) {
                $data[] = [];
                $progressBar->advance();

                continue;
            }

            // Handle duplicates
            $row = null;
            if ($dirtyLevel >= 2 && rand(1, 100) <= ($dirtyLevel * 5) && ! empty($duplicateRows)) {
                $row = $duplicateRows[array_rand($duplicateRows)];
            } else {
                $row = $this->generateRowData($i, $model, $config, $dirtyLevel, $withRelations, $relationsCount);
                if (rand(1, 10) === 1 && count($duplicateRows) < 100) {
                    $duplicateRows[] = $row;
                }
            }

            // Add dirty data
            if ($dirtyLevel > 0) {
                $row = $this->addDirtyDataToArray($row, $dirtyLevel);
            }

            // Add invalid data
            if ($dirtyLevel >= 3 && rand(1, 100) <= 10) {
                $row = $this->addInvalidDataToArray($row, $model, array_keys($row));
            }

            $data[] = $row;
            $progressBar->advance();
        }

        $progressBar->finish();

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($outputPath, $json);
    }

    /**
     * Generate XML file
     */
    private function generateXml(string $outputPath, string $model, int $numRows, int $dirtyLevel, bool $withRelations, ?int $relationsCount): void
    {
        $config = $this->getModelConfig($model, $withRelations, $relationsCount);
        $rootElement = strtolower($model).'s';
        $itemElement = strtolower($model);

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->createElement($rootElement);
        $xml->appendChild($root);

        $progressBar = $this->output->createProgressBar($numRows);
        $progressBar->start();

        $duplicateRows = [];

        for ($i = 1; $i <= $numRows; $i++) {
            // Handle empty rows (dirty level 2+)
            if ($dirtyLevel >= 2 && rand(1, 100) <= ($dirtyLevel * 5)) {
                $item = $xml->createElement($itemElement);
                $root->appendChild($item);
                $progressBar->advance();

                continue;
            }

            // Handle duplicates
            $row = null;
            if ($dirtyLevel >= 2 && rand(1, 100) <= ($dirtyLevel * 5) && ! empty($duplicateRows)) {
                $row = $duplicateRows[array_rand($duplicateRows)];
            } else {
                $row = $this->generateRowData($i, $model, $config, $dirtyLevel, $withRelations, $relationsCount);
                if (rand(1, 10) === 1 && count($duplicateRows) < 100) {
                    $duplicateRows[] = $row;
                }
            }

            // Add dirty data
            if ($dirtyLevel > 0) {
                $row = $this->addDirtyDataToArray($row, $dirtyLevel);
            }

            // Add invalid data
            if ($dirtyLevel >= 3 && rand(1, 100) <= 10) {
                $row = $this->addInvalidDataToArray($row, $model, array_keys($row));
            }

            $item = $xml->createElement($itemElement);
            $this->appendDataToXmlElement($xml, $item, $row);
            $root->appendChild($item);

            $progressBar->advance();
        }

        $progressBar->finish();

        $xml->save($outputPath);
    }

    /**
     * Recursively append data to XML element (handles nested arrays at any depth)
     */
    private function appendDataToXmlElement(\DOMDocument $xml, \DOMElement $parent, array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Check if it's a list (sequential numeric keys) or an associative array
                if (array_is_list($value)) {
                    // It's a list of items (e.g., books[], tags[])
                    $container = $xml->createElement($key);
                    $singularKey = Str::singular($key);
                    foreach ($value as $listItem) {
                        if (is_array($listItem)) {
                            $itemElement = $xml->createElement($singularKey);
                            $this->appendDataToXmlElement($xml, $itemElement, $listItem);
                            $container->appendChild($itemElement);
                        } else {
                            $itemElement = $xml->createElement($singularKey);
                            if ($listItem !== null && $listItem !== '') {
                                $itemElement->appendChild($xml->createTextNode(htmlspecialchars((string) $listItem, ENT_XML1, 'UTF-8')));
                            }
                            $container->appendChild($itemElement);
                        }
                    }
                    $parent->appendChild($container);
                } else {
                    // It's an associative array (e.g., profile object)
                    $element = $xml->createElement($key);
                    $this->appendDataToXmlElement($xml, $element, $value);
                    $parent->appendChild($element);
                }
            } else {
                $element = $xml->createElement($key);
                if ($value !== null && $value !== '') {
                    $element->appendChild($xml->createTextNode(htmlspecialchars((string) $value, ENT_XML1, 'UTF-8')));
                }
                $parent->appendChild($element);
            }
        }
    }

    /**
     * Get model configuration
     */
    private function getModelConfig(string $model, bool $withRelations, ?int $relationsCount): array
    {
        $baseConfig = match ($model) {
            'Author' => [
                'columns' => ['name', 'email', 'country'],
                'generators' => [
                    'name' => fn ($i) => fake()->name(),
                    'email' => fn ($i) => fake()->unique()->email(),
                    'country' => fn ($i) => strtoupper(fake()->countryCode()),
                ],
            ],
            'Book' => [
                'columns' => ['title', 'isbn', 'price', 'published_at', 'is_active', 'author_name'],
                'generators' => [
                    'title' => fn ($i) => fake()->sentence(rand(2, 6)),
                    'isbn' => fn ($i) => '978-'.str_pad((string) rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT),
                    'price' => fn ($i) => number_format(rand(500, 50000) / 100, 2),
                    'published_at' => fn ($i) => fake()->date('Y-m-d', 'now'),
                    'is_active' => fn ($i) => fake()->boolean(80) ? '1' : '0',
                    'author_name' => fn ($i) => fake()->name(),
                ],
            ],
            'Profile' => [
                'columns' => ['author_email', 'bio', 'website', 'verified_at'],
                'generators' => [
                    'author_email' => fn ($i) => fake()->unique()->email(),
                    'bio' => fn ($i) => fake()->optional(0.7)->paragraph(rand(1, 5)),
                    'website' => fn ($i) => fake()->optional(0.6)->url(),
                    'verified_at' => fn ($i) => fake()->optional(0.5)->dateTime('now')->format('Y-m-d H:i:s'),
                ],
            ],
            'Tag' => [
                'columns' => ['name', 'slug'],
                'generators' => [
                    'name' => fn ($i) => fake()->words(rand(1, 2), true),
                    'slug' => fn ($i) => Str::slug(fake()->words(rand(1, 2), true)),
                ],
            ],
            default => throw new \InvalidArgumentException("Unknown model: {$model}"),
        };

        // Add relations if requested
        if ($withRelations) {
            $baseConfig = $this->addRelationsToConfig($baseConfig, $model, $relationsCount);
        }

        return $baseConfig;
    }

    /**
     * Add relations to config
     */
    private function addRelationsToConfig(array $config, string $model, ?int $relationsCount): array
    {
        $relationsCount = $relationsCount ?? match ($model) {
            'Author' => 3, // books per author
            'Book' => 2,   // tags per book
            default => 1,
        };

        return match ($model) {
            'Author' => [
                'columns' => array_merge($config['columns'], ['profile', 'books']),
                'generators' => array_merge($config['generators'], [
                    'profile' => function ($i) {
                        $verifiedAt = fake()->optional(0.5)->dateTime('now');

                        return [
                            'bio' => fake()->optional(0.7)->paragraph(rand(1, 3)),
                            'website' => fake()->optional(0.6)->url(),
                            'verified_at' => $verifiedAt ? $verifiedAt->format('Y-m-d H:i:s') : null,
                        ];
                    },
                    'books' => function ($i) use ($relationsCount) {
                        return $this->generateBooks($relationsCount);
                    },
                ]),
            ],
            'Book' => [
                'columns' => array_merge($config['columns'], ['tags']),
                'generators' => array_merge($config['generators'], [
                    'tags' => function ($i) use ($relationsCount) {
                        return $this->generateTags($relationsCount);
                    },
                ]),
            ],
            default => $config,
        };
    }

    /**
     * Expand columns for CSV format (flatten profile, keep arrays as-is)
     */
    private function expandColumnsForCsv(array $columns, string $model, bool $withRelations): array
    {
        if (! $withRelations) {
            return $columns;
        }

        $expanded = [];
        foreach ($columns as $column) {
            if ($column === 'profile') {
                // Expand profile into dot-notation columns
                $expanded[] = 'profile.bio';
                $expanded[] = 'profile.website';
                $expanded[] = 'profile.verified_at';
            } else {
                $expanded[] = $column;
            }
        }

        return $expanded;
    }

    /**
     * Generate books array with tags
     */
    private function generateBooks(int $count, int $tagsPerBook = 3): array
    {
        $books = [];
        for ($j = 1; $j <= $count; $j++) {
            $books[] = [
                'title' => fake()->sentence(rand(2, 6)),
                'isbn' => '978-'.str_pad((string) rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT),
                'price' => number_format(rand(500, 50000) / 100, 2),
                'published_at' => fake()->date('Y-m-d', 'now'),
                'is_active' => fake()->boolean(80) ? '1' : '0',
                'tags' => $this->generateTags(rand(1, $tagsPerBook * 2)),
            ];
        }

        return $books;
    }

    /**
     * Generate tags array
     */
    private function generateTags(int $count): array
    {
        $tags = [];
        for ($j = 1; $j <= $count; $j++) {
            $name = fake()->words(rand(1, 2), true);
            $tags[] = [
                'name' => $name,
                'slug' => Str::slug($name),
            ];
        }

        return $tags;
    }

    /**
     * Generate a single row (array format)
     */
    private function generateRowData(int $rowNumber, string $model, array $config, int $dirtyLevel, bool $withRelations, ?int $relationsCount): array
    {
        $row = [];

        foreach ($config['columns'] as $column) {
            $generator = $config['generators'][$column] ?? fn () => null;
            $value = $generator($rowNumber);

            // Handle nested relations
            if ($withRelations && is_array($value)) {
                $row[$column] = $value;
            } else {
                $row[$column] = $value;
            }
        }

        return $row;
    }

    /**
     * Generate a single row (flat format for CSV)
     */
    private function generateRow(int $rowNumber, string $model, array $config, int $dirtyLevel, bool $withRelations, ?int $relationsCount): array
    {
        $row = [];

        foreach ($config['columns'] as $column) {
            $generator = $config['generators'][$column] ?? fn () => '';
            $value = $generator($rowNumber);

            // Flatten nested relations for CSV
            if ($withRelations && is_array($value)) {
                if ($column === 'books' || $column === 'tags') {
                    // For CSV, join with delimiter or use first item
                    $row[] = json_encode($value);
                } elseif ($column === 'profile') {
                    // Flatten profile fields
                    foreach ($value as $key => $val) {
                        $row[] = $val ?? '';
                    }
                } else {
                    $row[] = is_array($value) ? json_encode($value) : ($value ?? '');
                }
            } else {
                $row[] = $value ?? '';
            }
        }

        return $row;
    }

    /**
     * Add dirty data to row array
     */
    private function addDirtyDataToArray(array $row, int $dirtyLevel): array
    {
        if ($dirtyLevel === 0) {
            return $row;
        }

        // Add trailing/leading spaces (level 1+)
        if ($dirtyLevel >= 1) {
            $randomKey = array_rand($row);
            if (is_string($row[$randomKey])) {
                if (rand(1, 10) <= 3) {
                    $row[$randomKey] = $row[$randomKey].str_repeat(' ', rand(1, 3));
                }
                if (rand(1, 10) <= 3) {
                    $row[$randomKey] = str_repeat(' ', rand(1, 3)).$row[$randomKey];
                }
            }
        }

        // Add control characters (level 2+)
        if ($dirtyLevel >= 2) {
            $randomKey = array_rand($row);
            if (is_string($row[$randomKey]) && rand(1, 10) <= 2) {
                $controlChars = ["\x00", "\x01", "\x02", "\x1F"];
                $row[$randomKey] = $controlChars[array_rand($controlChars)].$row[$randomKey];
            }
        }

        return $row;
    }

    /**
     * Add dirty data to flat row array (CSV)
     */
    private function addDirtyData(array $row, int $dirtyLevel): array
    {
        if ($dirtyLevel === 0) {
            return $row;
        }

        // Add trailing/leading spaces (level 1+)
        if ($dirtyLevel >= 1) {
            $randomIndex = rand(0, count($row) - 1);
            if (rand(1, 10) <= 3) {
                $row[$randomIndex] = $row[$randomIndex].str_repeat(' ', rand(1, 3));
            }
            if (rand(1, 10) <= 3) {
                $row[$randomIndex] = str_repeat(' ', rand(1, 3)).$row[$randomIndex];
            }
        }

        // Add control characters (level 2+)
        if ($dirtyLevel >= 2) {
            $randomIndex = rand(0, count($row) - 1);
            if (rand(1, 10) <= 2) {
                $controlChars = ["\x00", "\x01", "\x02", "\x1F"];
                $row[$randomIndex] = $controlChars[array_rand($controlChars)].$row[$randomIndex];
            }
        }

        return $row;
    }

    /**
     * Add invalid data to row array
     */
    private function addInvalidDataToArray(array $row, string $model, array $columns): array
    {
        $corruptCount = rand(1, min(2, count($row)));
        $corruptedKeys = array_rand($row, $corruptCount);
        if (! is_array($corruptedKeys)) {
            $corruptedKeys = [$corruptedKeys];
        }

        foreach ($corruptedKeys as $key) {
            $columnName = $columns[$key] ?? $key;

            // Corrupt based on column name and model
            if (str_contains($columnName, 'email')) {
                $row[$key] = 'invalid-email-'.rand(1, 1000);
            } elseif (str_contains($columnName, 'date') || str_contains($columnName, '_at')) {
                $row[$key] = 'invalid-date-'.rand(1, 1000);
            } elseif (str_contains($columnName, 'isbn')) {
                $row[$key] = 'invalid-isbn';
            } elseif (str_contains($columnName, 'price') || str_contains($columnName, 'age')) {
                $row[$key] = 'not-a-number';
            } elseif (str_contains($columnName, 'country') && $model === 'Author') {
                $row[$key] = 'INVALID'; // Too long for size:2
            } elseif (str_contains($columnName, 'website') || str_contains($columnName, 'url')) {
                $row[$key] = 'not-a-valid-url';
            } else {
                $row[$key] = '';
            }
        }

        return $row;
    }

    /**
     * Add invalid data to flat row array (CSV)
     */
    private function addInvalidData(array $row, string $model, array $columns): array
    {
        $corruptCount = rand(1, min(2, count($row)));
        $corruptedIndices = array_rand($row, $corruptCount);
        if (! is_array($corruptedIndices)) {
            $corruptedIndices = [$corruptedIndices];
        }

        foreach ($corruptedIndices as $index) {
            $columnName = $columns[$index] ?? 'unknown';

            // Corrupt based on column name and model
            if (str_contains($columnName, 'email')) {
                $row[$index] = 'invalid-email-'.rand(1, 1000);
            } elseif (str_contains($columnName, 'date') || str_contains($columnName, '_at')) {
                $row[$index] = 'invalid-date-'.rand(1, 1000);
            } elseif (str_contains($columnName, 'isbn')) {
                $row[$index] = 'invalid-isbn';
            } elseif (str_contains($columnName, 'price') || str_contains($columnName, 'age')) {
                $row[$index] = 'not-a-number';
            } elseif (str_contains($columnName, 'country') && $model === 'Author') {
                $row[$index] = 'INVALID'; // Too long for size:2
            } elseif (str_contains($columnName, 'website') || str_contains($columnName, 'url')) {
                $row[$index] = 'not-a-valid-url';
            } else {
                $row[$index] = '';
            }
        }

        return $row;
    }

    /**
     * Format CSV row manually
     */
    private function formatCsvRow(array $row): string
    {
        $formatted = [];
        foreach ($row as $value) {
            $value = str_replace('"', '""', (string) $value);
            if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r")) {
                $formatted[] = '"'.$value.'"';
            } else {
                $formatted[] = $value;
            }
        }

        return implode(',', $formatted);
    }

    /**
     * Get newline character (with optional mixing)
     */
    private function getNewline(bool $mixed): string
    {
        if (! $mixed) {
            return "\n";
        }

        return match (rand(1, 3)) {
            1 => "\r\n", // CRLF
            2 => "\n",   // LF
            3 => "\r",   // CR
        };
    }
}

