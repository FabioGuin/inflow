# CLI Commands Usage

Complete guide to using Laravel InFlow commands.

## Available Commands

### `inflow:process` (Implemented)

Executes the import using a `mapping.json` file.

**Syntax**:
```bash
php artisan inflow:process {file} --mapping= [--sanitize=] [--newline-format=] [--preview=] [--error-report]
```

**Parameters**:
- `file` (required) - Path to source file
- `--mapping` (required) - Path to `mapping.json` file
- `--sanitize` (optional) - Apply sanitization (1/0, true/false, y/n - default: from mapping)
- `--newline-format` (optional) - Newline format (lf, crlf, cr - default: from mapping)
- `--preview` (optional) - Number of rows to preview (default: 5)
- `--error-report` (optional) - Generate detailed error report file on failure

**Example**:
```bash
php artisan inflow:process data/users.csv --mapping=mapping.json
php artisan inflow:process data/users.csv --mapping=mapping.json --sanitize=1
php artisan inflow:process data/users.csv --mapping=mapping.json --preview=10
```

**Behavior**:
1. Loads `mapping.json`
2. Validates mapping (structure, dependencies, models)
3. Applies sanitization if configured
4. Detects file format
5. Executes import with chunked processing
6. Outputs results and summary

**Exit Codes**:
- `0` - Import completed successfully
- `1` - Errors during import

### `inflow:make-mapping` (Implemented)

Creates or configures a mapping file interactively.

**Syntax**:
```bash
php artisan inflow:make-mapping {file} [model] [--output=] [--force] [--sanitize] [--sanitize-on-run]
```

**Parameters**:
- `file` (required) - Source file path (CSV/Excel/JSON)
- `model` (optional) - Target model class (FQCN) - will prompt if not provided
- `--output` (optional) - Path to save mapping file (default: mappings/{ModelClass}.json)
- `--force` (optional) - Overwrite existing mapping file
- `--sanitize` (optional) - Apply sanitization to the file before analysis
- `--sanitize-on-run` (optional) - Include sanitizer config in flow_config for recurring processes

**Example**:
```bash
php artisan inflow:make-mapping data/users.csv App\\Models\\User
php artisan inflow:make-mapping data/users.csv --output=custom-mapping.json
php artisan inflow:make-mapping data/users.csv App\\Models\\User --force
```

**Behavior**:
1. Analyzes the source file
2. Prompts for model selection (if not provided)
3. Interactive column mapping
4. Handles relations and transformations
5. Saves mapping file

### `inflow:test-execution-order` (Development)

Test command for ExecutionOrderService. Allows testing the execution order suggestion.

**Syntax**:
```bash
php artisan inflow:test-execution-order [models...] [--validate]
```

**Parameters**:
- `models` (optional) - Model classes to analyze (space-separated)
- `--validate` (optional) - Validate execution order instead of suggesting

**Example**:
```bash
php artisan inflow:test-execution-order App\\Models\\Author App\\Models\\Book
```

### `inflow:test-model-dependency` (Development)

Test command for ModelDependencyService. Tests model dependency analysis.

**Syntax**:
```bash
php artisan inflow:test-model-dependency [models...]
```

## Planned Commands

### `inflow:analyze` (Planned)

Analyzes a file and generates `analysis.json`.

**Planned Syntax**:
```bash
php artisan inflow:analyze {file} [--output=] [--sanitize]
```

**Planned Output**: `analysis.json` with:
- `source_schema` - Complete schema (columns, types, examples)
- `quality_report` - Data quality report
- `detected_format` - Detected format
- `sanitization_report` - Sanitization report (if applied)

### `inflow:validate` (Planned)

Validates a `mapping.json` file.

**Planned Syntax**:
```bash
php artisan inflow:validate {mapping} [--analysis=]
```

**Planned Validations**:
- Valid JSON structure
- Dependencies (unique execution_order)
- Schema compatibility (if `--analysis` provided)
- Existing Eloquent models

## Common Options

### Quiet Mode (`--quiet`)

For automated executions (cron, scripts):

```bash
php artisan inflow:process file.csv --mapping=mapping.json --quiet
```

Minimal output, only critical errors.

### Verbose Mode (`-v`, `-vv`, `-vvv`)

For debugging:

```bash
php artisan inflow:process file.csv --mapping=mapping.json -vvv
```

## Practical Examples

### Simple Import

```bash
# 1. Create mapping interactively
php artisan inflow:make-mapping products.csv App\\Models\\Product

# 2. Import
php artisan inflow:process products.csv --mapping=mappings/Product.json
```

### Import with Sanitization

```bash
# Create mapping with sanitization
php artisan inflow:make-mapping dirty_file.csv App\\Models\\User --sanitize

# Or specify during import
php artisan inflow:process dirty_file.csv --mapping=mapping.json --sanitize=1
```

### Import with Preview

```bash
# Preview first 10 rows before full import
php artisan inflow:process file.csv --mapping=mapping.json --preview=10
```

## Script Integration

### Bash Script

```bash
#!/bin/bash

FILE="data/users.csv"
MAPPING="mapping.json"

# Create mapping if it doesn't exist
if [ ! -f "$MAPPING" ]; then
    php artisan inflow:make-mapping "$FILE" App\\Models\\User --output="$MAPPING" --force
fi

# Import
php artisan inflow:process "$FILE" --mapping="$MAPPING" --quiet || exit 1

echo "Import completed!"
```

### Cron Job

```cron
0 2 * * * cd /path/to/project && php artisan inflow:process /path/to/file.csv --mapping=/path/to/mapping.json --quiet >> /var/log/inflow.log 2>&1
```

## Error Handling

### Import Failed

```bash
php artisan inflow:process file.csv --mapping=mapping.json
# Processing failed: Validation error on row 5
# Exit code: 1
```

Use `--error-report` to generate detailed error report:

```bash
php artisan inflow:process file.csv --mapping=mapping.json --error-report
# Generates: storage/inflow/reports/error-report-{timestamp}.txt
```

## Best Practices

1. **Use interactive mapping**: `inflow:make-mapping` for easier setup
2. **Preview before import**: Use `--preview` to verify mapping
3. **Use --quiet for automation**: Minimal output for scripts/cron
4. **Use -v for debug**: Verbose mode for troubleshooting
5. **Save mapping files**: Reuse mappings for recurring imports

## Next Steps

- See [Workflow](workflow.md) for complete scenarios
- Consult [Mapping JSON Schema](mapping-json-schema.md) for mapping details
- Read [Architecture](architecture.md) to understand the system
