<?php

namespace InFlow\ValueObjects;

use InFlow\Contracts\ReaderInterface;
use InFlow\Sources\FileSource;

/**
 * Immutable value object that holds state throughout the ETL processing pipeline.
 *
 * This context is passed through each pipe in the pipeline, accumulating data
 * as processing progresses. Each pipe can create a new instance with updated
 * values using the `with*()` methods, maintaining immutability.
 *
 * The context tracks:
 * - File metadata (path, source, content, line count)
 * - Processing decisions (should sanitize, format detected)
 * - Pipeline results (reader, schema, mapping, flow run)
 * - Configuration and state (guided config, cancellation status)
 */
class ProcessingContext
{
    /**
     * @param  string  $filePath  Original file path provided by user
     * @param  FileSource|null  $source  FileSource instance after file loading
     * @param  string|null  $content  Raw file content after reading
     * @param  int|null  $lineCount  Number of lines in the content
     * @param  bool|null  $shouldSanitize  Whether sanitization was applied
     * @param  DetectedFormat|null  $format  Detected file format (CSV, Excel, etc.)
     * @param  ReaderInterface|null  $reader  Reader instance for data access
     * @param  SourceSchema|null  $sourceSchema  Profiled schema of the data
     * @param  MappingDefinition|null  $mappingDefinition  Mapping configuration
     * @param  FlowRun|null  $flowRun  Flow execution result
     * @param  float|null  $startTime  Processing start timestamp (microtime)
     * @param  array  $guidedConfig  Configuration from interactive wizard
     * @param  bool  $cancelled  Whether processing was cancelled by user
     */
    public function __construct(
        public string $filePath,
        public ?FileSource $source = null,
        public ?string $content = null,
        public ?int $lineCount = null,
        public ?bool $shouldSanitize = null,
        public ?DetectedFormat $format = null,
        public ?ReaderInterface $reader = null,
        public ?SourceSchema $sourceSchema = null,
        public ?MappingDefinition $mappingDefinition = null,
        public ?FlowRun $flowRun = null,
        public ?float $startTime = null,
        public array $guidedConfig = [],
        public bool $cancelled = false,
    ) {}

    /**
     * Create a new instance with updated FileSource.
     *
     * @param  FileSource  $source  The FileSource instance
     * @return self New instance with updated source
     */
    public function withSource(FileSource $source): self
    {
        $new = clone $this;
        $new->source = $source;

        return $new;
    }

    /**
     * Create a new instance with updated file content.
     *
     * @param  string  $content  The raw file content
     * @return self New instance with updated content
     */
    public function withContent(string $content): self
    {
        $new = clone $this;
        $new->content = $content;

        return $new;
    }

    /**
     * Create a new instance with updated line count.
     *
     * @param  int  $lineCount  Number of lines in the content
     * @return self New instance with updated line count
     */
    public function withLineCount(int $lineCount): self
    {
        $new = clone $this;
        $new->lineCount = $lineCount;

        return $new;
    }

    /**
     * Create a new instance with updated sanitization flag.
     *
     * @param  bool  $shouldSanitize  Whether sanitization was applied
     * @return self New instance with updated flag
     */
    public function withShouldSanitize(bool $shouldSanitize): self
    {
        $new = clone $this;
        $new->shouldSanitize = $shouldSanitize;

        return $new;
    }

    /**
     * Create a new instance with updated detected format.
     *
     * @param  DetectedFormat  $format  The detected file format
     * @return self New instance with updated format
     */
    public function withFormat(DetectedFormat $format): self
    {
        $new = clone $this;
        $new->format = $format;

        return $new;
    }

    /**
     * Create a new instance with updated reader.
     *
     * @param  ReaderInterface  $reader  The reader instance for data access
     * @return self New instance with updated reader
     */
    public function withReader(ReaderInterface $reader): self
    {
        $new = clone $this;
        $new->reader = $reader;

        return $new;
    }

    /**
     * Create a new instance with updated source schema.
     *
     * @param  SourceSchema  $sourceSchema  The profiled schema of the data
     * @return self New instance with updated schema
     */
    public function withSourceSchema(SourceSchema $sourceSchema): self
    {
        $new = clone $this;
        $new->sourceSchema = $sourceSchema;

        return $new;
    }

    /**
     * Create a new instance with updated mapping definition.
     *
     * @param  MappingDefinition  $mappingDefinition  The mapping configuration
     * @return self New instance with updated mapping
     */
    public function withMappingDefinition(MappingDefinition $mappingDefinition): self
    {
        $new = clone $this;
        $new->mappingDefinition = $mappingDefinition;

        return $new;
    }

    /**
     * Create a new instance with updated flow run result.
     *
     * @param  FlowRun  $flowRun  The flow execution result
     * @return self New instance with updated flow run
     */
    public function withFlowRun(FlowRun $flowRun): self
    {
        $new = clone $this;
        $new->flowRun = $flowRun;

        return $new;
    }

    /**
     * Create a new instance with updated start time.
     *
     * @param  float  $startTime  Processing start timestamp (microtime)
     * @return self New instance with updated start time
     */
    public function withStartTime(float $startTime): self
    {
        $new = clone $this;
        $new->startTime = $startTime;

        return $new;
    }

    /**
     * Create a new instance with updated guided configuration.
     *
     * @param  array  $guidedConfig  Configuration from interactive wizard
     * @return self New instance with updated guided config
     */
    public function withGuidedConfig(array $guidedConfig): self
    {
        $new = clone $this;
        $new->guidedConfig = $guidedConfig;

        return $new;
    }

    /**
     * Create a new instance with updated cancellation status.
     *
     * @param  bool  $cancelled  Whether processing was cancelled (default: true)
     * @return self New instance with updated cancellation status
     */
    public function withCancelled(bool $cancelled = true): self
    {
        $new = clone $this;
        $new->cancelled = $cancelled;

        return $new;
    }
}
