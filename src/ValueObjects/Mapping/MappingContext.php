<?php

namespace InFlow\ValueObjects\Mapping;

use InFlow\Contracts\ReaderInterface;
use InFlow\Sources\FileSource;
use InFlow\ValueObjects\Data\SourceSchema;
use InFlow\ValueObjects\File\DetectedFormat;

/**
 * Immutable value object that holds state throughout the mapping creation process.
 *
 * Similar to ProcessingContext but focused on mapping creation workflow.
 */
class MappingContext
{
    /**
     * @param  string  $filePath  Original file path provided by user
     * @param  FileSource|null  $source  FileSource instance after file loading
     * @param  DetectedFormat|null  $format  Detected file format
     * @param  ReaderInterface|null  $reader  Reader instance for data access
     * @param  SourceSchema|null  $sourceSchema  Profiled schema of the data
     * @param  string|null  $modelClass  Selected target model class (FQCN)
     * @param  array|null  $dependencyAnalysis  Model dependency analysis result
     * @param  array|null  $mappingData  Created mapping data (to be saved)
     * @param  string|null  $outputPath  Path where mapping will be saved
     * @param  bool  $cancelled  Whether mapping creation was cancelled by user
     */
    public function __construct(
        public string $filePath,
        public ?FileSource $source = null,
        public ?DetectedFormat $format = null,
        public ?ReaderInterface $reader = null,
        public ?SourceSchema $sourceSchema = null,
        public ?string $modelClass = null,
        public ?array $dependencyAnalysis = null,
        public ?array $mappingData = null,
        public ?string $outputPath = null,
        public bool $cancelled = false,
    ) {}

    public function withSource(FileSource $source): self
    {
        $new = clone $this;
        $new->source = $source;

        return $new;
    }

    public function withFormat(DetectedFormat $format): self
    {
        $new = clone $this;
        $new->format = $format;

        return $new;
    }

    public function withReader(ReaderInterface $reader): self
    {
        $new = clone $this;
        $new->reader = $reader;

        return $new;
    }

    public function withSourceSchema(SourceSchema $sourceSchema): self
    {
        $new = clone $this;
        $new->sourceSchema = $sourceSchema;

        return $new;
    }

    public function withModelClass(string $modelClass): self
    {
        $new = clone $this;
        $new->modelClass = $modelClass;

        return $new;
    }

    public function withDependencyAnalysis(array $dependencyAnalysis): self
    {
        $new = clone $this;
        $new->dependencyAnalysis = $dependencyAnalysis;

        return $new;
    }

    public function withMappingData(array $mappingData): self
    {
        $new = clone $this;
        $new->mappingData = $mappingData;

        return $new;
    }

    public function withOutputPath(string $outputPath): self
    {
        $new = clone $this;
        $new->outputPath = $outputPath;

        return $new;
    }

    public function withCancelled(bool $cancelled = true): self
    {
        $new = clone $this;
        $new->cancelled = $cancelled;

        return $new;
    }
}

