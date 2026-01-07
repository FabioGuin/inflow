# Complete Workflow

Detailed guide to working with Laravel InFlow.

## Scenario 1: Initial Import (First Time)

### Step 1: Analyze the File

```bash
php artisan inflow:analyze data/users.csv
```

**Output**: `data/analysis.json`

Contains:
- Complete schema (columns, types, examples)
- Quality report (anomalies, statistics)
- Detected format

### Step 2: Configure Mapping

#### Option A: Via MCP (Recommended)

If you have MCP configured, use the tools:

1. **Get suggestions**:
   ```json
   {
     "tool": "inflow_suggest_mapping",
     "arguments": {
       "file_path": "data/users.csv",
       "model_class": "App\\Models\\User"
     }
   }
   ```

2. **Build mapping**:
   ```json
   {
     "tool": "inflow_build_mapping",
     "arguments": {
       "file_path": "data/users.csv",
       "model_class": "App\\Models\\User",
       "answers": {
         "email": "email",
         "nome": "name",
         "eta": "age"
       },
       "output_path": "mapping.json"
     }
   }
   ```

#### Option B: Manual

Create `mapping.json` following the [schema](mapping-json-schema.md).

### Step 3: Validate

```bash
php artisan inflow:validate mapping.json --analysis=data/analysis.json
```

Verifies:
- Valid JSON structure
- Dependencies (unique execution_order)
- Schema compatibility
- Existing Eloquent models

### Step 4: Run Import

```bash
php artisan inflow:process data/users.csv --mapping=mapping.json
```

## Scenario 2: Recurring Import (Automated)

For recurring imports (e.g., nightly cron), add `flow_config` to the mapping:

```json
{
  "version": "1.0",
  "name": "Product Catalog Nightly Import",
  "flow_config": {
    "sanitizer": {
      "enabled": true,
      "remove_bom": true,
      "normalize_newlines": true
    },
    "format": {
      "type": "csv",
      "delimiter": ",",
      "has_header": true
    },
    "execution": {
      "chunk_size": 1000,
      "error_policy": "continue"
    }
  },
  "mappings": [...]
}
```

Then run:

```bash
php artisan inflow:process catalog.csv --mapping=mapping.json --quiet
```

The command:
- Uses `flow_config` for sanitization/format
- Requires no interaction
- Handles errors according to `error_policy`
- Minimal output (--quiet)

## Scenario 3: Import with Relations

### Sequential Mapping

For nested relations, use `execution_order`:

```json
{
  "mappings": [
    {
      "model": "App\\Models\\Author",
      "execution_order": 1,
      "columns": [
        {"source": "author_email", "target": "email"},
        {"source": "author_name", "target": "name"}
      ],
      "options": {
        "unique_key": "email",
        "duplicate_strategy": "update"
      }
    },
    {
      "model": "App\\Models\\Book",
      "execution_order": 2,
      "columns": [
        {"source": "book_title", "target": "title"},
        {
          "source": "author_email",
          "target": "author.email",
          "relation_lookup": {
            "field": "email"
          }
        }
      ]
    }
  ]
}
```

**Execution order**:
1. First imports `Author` (execution_order: 1)
2. Then imports `Book` with relation to `Author` (execution_order: 2)

## Scenario 4: Import with Transformations

```json
{
  "mappings": [
    {
      "model": "App\\Models\\User",
      "columns": [
        {
          "source": "email_raw",
          "target": "email",
          "transforms": ["trim", "lower"]
        },
        {
          "source": "prezzo_centesimi",
          "target": "price",
          "transforms": ["cast:float", "divide:100"]
        },
        {
          "source": "data_nascita",
          "target": "birth_date",
          "transforms": ["cast:date"]
        }
      ]
    }
  ]
}
```

## Scenario 5: Validation and Debug

### Standalone Validation

```bash
php artisan inflow:validate mapping.json
```

### Validation with Schema

```bash
php artisan inflow:validate mapping.json --analysis=analysis.json
```

### Verbose Debug

```bash
php artisan inflow:process file.csv --mapping=mapping.json -v
```

## Best Practices

### 1. Always Analyze First

Always analyze the file before creating mapping:
```bash
php artisan inflow:analyze file.csv
```

### 2. Validate Before Executing

Always validate mapping before import:
```bash
php artisan inflow:validate mapping.json
```

### 3. Use flow_config for Automation

For recurring imports, configure `flow_config` in the mapping.

### 4. Handle Duplicates

Use `duplicate_strategy`:
- `error` - Stop on duplicate (default)
- `skip` - Skip duplicates
- `update` - Update existing records

### 5. Order Mappings for Relations

Use `execution_order` to handle dependencies:
- Parent models first (order: 1)
- Child models after (order: 2+)

## Troubleshooting

### File not analyzed correctly

```bash
# Force sanitization
php artisan inflow:analyze file.csv --sanitize
```

### Mapping not valid

```bash
# Validate with details
php artisan inflow:validate mapping.json -v
```

### Import fails

```bash
# Run with verbose for details
php artisan inflow:process file.csv --mapping=mapping.json -vvv
```

## Next Steps

- See [Mapping JSON Schema](mapping-json-schema.md) for complete details
- Consult [Nested Relations](nested-relations.md) for complex scenarios
- Read [Architecture](architecture.md) to understand the system
