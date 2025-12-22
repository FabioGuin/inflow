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
        'enabled' => env('INFLOW_SANITIZER_ENABLED', true),
        'remove_bom' => true,
        'normalize_newlines' => true,
        'newline_format' => 'lf', // 'lf', 'crlf', or 'cr'
        'remove_control_chars' => true,
        'handle_truncated_eof' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution Options
    |--------------------------------------------------------------------------
    |
    | Configuration for ETL execution behavior.
    |
    */

    'execution' => [
        // Chunk size for processing rows (default: 1000)
        'chunk_size' => env('INFLOW_CHUNK_SIZE', 1000),

        // Error handling policy: 'stop' (fail on first error) or 'continue' (collect errors and continue)
        'error_policy' => env('INFLOW_ERROR_POLICY', 'continue'),

        // Skip empty rows during import
        'skip_empty_rows' => env('INFLOW_SKIP_EMPTY_ROWS', true),

        // Truncate string fields that exceed column maximum length
        'truncate_long_fields' => env('INFLOW_TRUNCATE_LONG_FIELDS', true),

        // Number of rows to preview when reading file (default: 5)
        'preview_rows' => env('INFLOW_PREVIEW_ROWS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mappings Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for mapping file storage.
    |
    */

    'mappings' => [
        // Directory path where mapping files are stored (relative to package root or absolute)
        'path' => env('INFLOW_MAPPINGS_PATH', 'mappings'),
    ],

];
