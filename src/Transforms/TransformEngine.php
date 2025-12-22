<?php

namespace InFlow\Transforms;

use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Date\DateFormatTransform;
use InFlow\Transforms\Date\ParseDateTransform;
use InFlow\Transforms\Date\TimestampTransform;
use InFlow\Transforms\Numeric\CeilTransform;
use InFlow\Transforms\Numeric\DivideTransform;
use InFlow\Transforms\Numeric\FloorTransform;
use InFlow\Transforms\Numeric\FromCentsTransform;
use InFlow\Transforms\Numeric\MultiplyTransform;
use InFlow\Transforms\Numeric\RoundTransform;
use InFlow\Transforms\Numeric\ToCentsTransform;
use InFlow\Transforms\String\CamelCaseTransform;
use InFlow\Transforms\String\CapitalizeTransform;
use InFlow\Transforms\String\CleanWhitespaceTransform;
use InFlow\Transforms\String\LowerTransform;
use InFlow\Transforms\String\NormalizeMultilineTransform;
use InFlow\Transforms\String\PrefixTransform;
use InFlow\Transforms\String\SlugifyTransform;
use InFlow\Transforms\String\SnakeCaseTransform;
use InFlow\Transforms\String\StripTagsTransform;
use InFlow\Transforms\String\SuffixTransform;
use InFlow\Transforms\String\TitleTransform;
use InFlow\Transforms\String\TrimTransform;
use InFlow\Transforms\String\TruncateTransform;
use InFlow\Transforms\String\UpperTransform;
use InFlow\Transforms\Utility\CastTransform;
use InFlow\Transforms\Utility\CoalesceTransform;
use InFlow\Transforms\Utility\ConcatTransform;
use InFlow\Transforms\Utility\DefaultTransform;
use InFlow\Transforms\Utility\HashTransform;
use InFlow\Transforms\Utility\JsonDecodeTransform;
use InFlow\Transforms\Utility\NullIfEmptyTransform;
use InFlow\Transforms\Utility\RegexReplaceTransform;
use InFlow\Transforms\Utility\SplitTransform;

/**
 * Engine for applying transformation pipelines
 *
 * This engine processes transformation pipelines defined in the mapping DSL.
 * Transformations can be specified as pipe-separated strings (e.g., "trim|lower|cast:int")
 * or as arrays of transform specifications.
 *
 * The transformation pipeline follows a functional programming approach where
 * each transform is applied sequentially to the value, creating a data transformation
 * pipeline similar to Unix pipes or Laravel's collection methods.
 *
 * Custom transforms can be registered via:
 * 1. Config file: config/inflow.php -> 'transforms' array
 * 2. Programmatically: $engine->register('name', new MyTransform())
 *
 * @see MappingBuilder For DSL definition of transformations in mappings
 * @see TransformStepInterface For custom transformation implementations
 *
 * @example
 * ```php
 * $engine = new TransformEngine();
 * $result = $engine->apply('  HELLO  ', ['trim', 'lower']);  // Returns: "hello"
 * $result = $engine->apply('123', explode('|', 'trim|cast:int'));  // Returns: 123
 * ```
 */
class TransformEngine
{
    /**
     * Registry of transform instances (simple transforms)
     *
     * @var array<string, TransformStepInterface>
     */
    private array $transforms = [];

    /**
     * Registry of custom transform classes (for parameterized transforms)
     *
     * @var array<string, class-string<TransformStepInterface>>
     */
    private array $customTransformClasses = [];

    public function __construct()
    {
        $this->registerBuiltInTransforms();
        $this->registerCustomTransforms();
    }

    /**
     * Register a transform
     */
    public function register(string $name, TransformStepInterface $transform): void
    {
        $this->transforms[$name] = $transform;
    }

    /**
     * Apply a pipeline of transformations to a value
     *
     * @param  array<string>  $transformSpecs
     * @param  array<string, mixed>  $context
     */
    public function apply(mixed $value, array $transformSpecs, array $context = []): mixed
    {
        $result = $value;

        foreach ($transformSpecs as $spec) {
            $transform = $this->resolveTransform($spec);
            $result = $transform->transform($result, $context);
        }

        return $result;
    }


    /**
     * Resolve a transform specification to a TransformStepInterface instance
     */
    private function resolveTransform(string $spec): TransformStepInterface
    {
        $spec = trim($spec);

        // Check if already registered as instance
        if (isset($this->transforms[$spec])) {
            return $this->transforms[$spec];
        }

        // Check custom transforms first (allows overriding built-ins)
        $customTransform = $this->resolveCustomTransform($spec);
        if ($customTransform !== null) {
            return $customTransform;
        }

        // Try to resolve built-in transforms
        return match (true) {
            // Simple transforms (no parameters)
            $spec === 'trim' => new TrimTransform,
            $spec === 'upper' => new UpperTransform,
            $spec === 'lower' => new LowerTransform,
            $spec === 'capitalize' => new CapitalizeTransform,
            $spec === 'slugify' => new SlugifyTransform,
            $spec === 'title' => new TitleTransform,
            $spec === 'snake_case' => new SnakeCaseTransform,
            $spec === 'camel_case' => new CamelCaseTransform,
            $spec === 'strip_tags' => new StripTagsTransform,
            $spec === 'clean_whitespace' => new CleanWhitespaceTransform,
            $spec === 'normalize_multiline' => new NormalizeMultilineTransform,
            $spec === 'null_if_empty' => new NullIfEmptyTransform,
            $spec === 'floor' => new FloorTransform,
            $spec === 'ceil' => new CeilTransform,
            $spec === 'to_cents' => new ToCentsTransform,
            $spec === 'from_cents' => new FromCentsTransform,
            $spec === 'timestamp' => new TimestampTransform,
            $spec === 'json_decode' => new JsonDecodeTransform,

            // Parameterized transforms
            str_starts_with($spec, 'cast:') => CastTransform::fromString($spec),
            str_starts_with($spec, 'default:') => DefaultTransform::fromString($spec),
            str_starts_with($spec, 'hash:') => HashTransform::fromString($spec),
            str_starts_with($spec, 'truncate:') => TruncateTransform::fromString($spec),
            str_starts_with($spec, 'prefix:') => PrefixTransform::fromString($spec),
            str_starts_with($spec, 'suffix:') => SuffixTransform::fromString($spec),
            str_starts_with($spec, 'round:') => RoundTransform::fromString($spec),
            str_starts_with($spec, 'multiply:') => MultiplyTransform::fromString($spec),
            str_starts_with($spec, 'divide:') => DivideTransform::fromString($spec),
            str_starts_with($spec, 'date_format:') => DateFormatTransform::fromString($spec),
            str_starts_with($spec, 'parse_date:') => ParseDateTransform::fromString($spec),
            str_starts_with($spec, 'coalesce:') => CoalesceTransform::fromString($spec),
            str_starts_with($spec, 'split:') => SplitTransform::fromString($spec),
            str_starts_with($spec, 'concat(') => ConcatTransform::fromString($spec),
            str_starts_with($spec, 'regex_replace(') => RegexReplaceTransform::fromString($spec),

            default => throw new \InvalidArgumentException("Unknown transform: {$spec}"),
        };
    }

    /**
     * Resolve a custom transform from the registered classes.
     *
     * Supports both simple transforms (e.g., "my_transform") and
     * parameterized transforms (e.g., "my_transform:param").
     */
    private function resolveCustomTransform(string $spec): ?TransformStepInterface
    {
        // Check for exact match (simple transform)
        if (isset($this->customTransformClasses[$spec])) {
            $class = $this->customTransformClasses[$spec];

            return new $class;
        }

        // Check for parameterized transform (e.g., "my_transform:param")
        foreach ($this->customTransformClasses as $name => $class) {
            if (str_starts_with($spec, $name.':')) {
                // Check if class has fromString method
                if (method_exists($class, 'fromString')) {
                    return $class::fromString($spec);
                }

                // Fallback: create instance without parameters
                \inflow_report(
                    new \RuntimeException("Transform {$name} doesn't support parameters (missing fromString method)"),
                    'warning',
                    ['spec' => $spec, 'class' => $class]
                );

                return new $class;
            }
        }

        return null;
    }

    /**
     * Register built-in transforms
     */
    private function registerBuiltInTransforms(): void
    {
        // String transforms
        $this->transforms['trim'] = new TrimTransform;
        $this->transforms['upper'] = new UpperTransform;
        $this->transforms['lower'] = new LowerTransform;
        $this->transforms['capitalize'] = new CapitalizeTransform;
        $this->transforms['slugify'] = new SlugifyTransform;
        $this->transforms['title'] = new TitleTransform;
        $this->transforms['snake_case'] = new SnakeCaseTransform;
        $this->transforms['camel_case'] = new CamelCaseTransform;
        $this->transforms['strip_tags'] = new StripTagsTransform;
        $this->transforms['clean_whitespace'] = new CleanWhitespaceTransform;
        $this->transforms['normalize_multiline'] = new NormalizeMultilineTransform;
        $this->transforms['null_if_empty'] = new NullIfEmptyTransform;

        // Numeric transforms
        $this->transforms['floor'] = new FloorTransform;
        $this->transforms['ceil'] = new CeilTransform;
        $this->transforms['to_cents'] = new ToCentsTransform;
        $this->transforms['from_cents'] = new FromCentsTransform;

        // Date transforms
        $this->transforms['timestamp'] = new TimestampTransform;

        // Utility transforms
        $this->transforms['json_decode'] = new JsonDecodeTransform;
    }

    /**
     * Register custom transforms from config.
     *
     * Custom transforms are registered as class references, not instances,
     * to support parameterized transforms with fromString().
     */
    private function registerCustomTransforms(): void
    {
        // Only load from config if running in Laravel context
        if (! function_exists('config')) {
            return;
        }

        /** @var array<string, class-string<TransformStepInterface>> $customTransforms */
        $customTransforms = config('inflow.transforms', []);

        foreach ($customTransforms as $name => $class) {
            if (! class_exists($class)) {
                \inflow_report(
                    new \RuntimeException("Custom transform class not found: {$class}"),
                    'warning',
                    ['name' => $name, 'class' => $class]
                );

                continue;
            }

            if (! is_subclass_of($class, TransformStepInterface::class)) {
                \inflow_report(
                    new \RuntimeException("Custom transform must implement TransformStepInterface: {$class}"),
                    'warning',
                    ['name' => $name, 'class' => $class]
                );

                continue;
            }

            $this->customTransformClasses[$name] = $class;
        }
    }

    /**
     * Register a custom transform class programmatically.
     *
     * @param  class-string<TransformStepInterface>  $class
     */
    public function registerClass(string $name, string $class): void
    {
        $this->customTransformClasses[$name] = $class;
    }

    /**
     * Get all registered custom transform names.
     *
     * @return array<string>
     */
    public function getCustomTransformNames(): array
    {
        return array_keys($this->customTransformClasses);
    }
}
