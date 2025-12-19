<?php

return [
    /*
    |--------------------------------------------------------------------------
    | InFlow ETL Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Laravel InFlow ETL Engine
    |
    */

    'log_channel' => env('INFLOW_LOG_CHANNEL', 'inflow'),

    /*
    |--------------------------------------------------------------------------
    | Custom Transforms
    |--------------------------------------------------------------------------
    |
    | Register custom transform classes here. Each transform must implement
    | InFlow\Contracts\TransformStepInterface.
    |
    | For parameterized transforms (e.g., "my_transform:param"), the class
    | must also implement a static fromString(string $spec): self method.
    |
    | For interactive transforms (CLI prompts), implement
    | InFlow\Transforms\Contracts\InteractiveTransformInterface.
    |
    | Example:
    |   'my_transform' => \App\Transforms\MyCustomTransform::class,
    |   'company_code' => \App\Transforms\CompanyCodeTransform::class,
    |
    */

    'transforms' => [
        // 'my_transform' => \App\Transforms\MyCustomTransform::class,
    ],

    'sanitizer' => [
        'remove_bom' => true,
        'normalize_newlines' => true,
        'newline_format' => "\n", // LF
        'remove_control_chars' => true,
        'handle_truncated_eof' => true,
    ],

    'reader' => [
        'chunk_size' => 1000,
        'streaming' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Command Default Options
    |--------------------------------------------------------------------------
    |
    | Default values for inflow:process command options.
    | These can be overridden via command line arguments.
    |
    */

    'command' => [
        // Apply sanitization by default
        'sanitize' => env('INFLOW_SANITIZE', true),

        // Default output path (null = stdout)
        'output' => env('INFLOW_OUTPUT', null),

        // Newline format: lf, crlf, cr
        'newline_format' => env('INFLOW_NEWLINE_FORMAT', 'lf'),

        // BOM removal enabled by default
        'bom_removal' => env('INFLOW_BOM_REMOVAL', true),

        // Control characters removal enabled by default
        'control_chars_removal' => env('INFLOW_CONTROL_CHARS_REMOVAL', true),

        // Number of rows to preview
        'preview' => env('INFLOW_PREVIEW', 5),

        // Custom mapping file path (null = auto-detect from model)
        'mapping' => env('INFLOW_MAPPING', null),
    ],
];
