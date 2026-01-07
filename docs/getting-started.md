# Getting Started

Quick guide to get started with Laravel InFlow.

## Installation

```bash
composer require fabio-guin/laravel-inflow
```

## Basic Workflow

### 1. Create the Mapping

You have two options:

#### Option A: Interactive Command (Recommended)

Use the interactive command to create a mapping:

```bash
php artisan inflow:make-mapping path/to/file.csv App\\Models\\User
```

This command will:
- Analyze the file structure
- Prompt you to map columns to model attributes
- Handle relations and transformations
- Save the mapping as `mapping.json`

#### Option B: Create Manually

Create a `mapping.json` file following the [complete schema](mapping-json-schema.md):

```json
{
  "version": "1.0",
  "name": "User Import",
  "mappings": [
    {
      "model": "App\\Models\\User",
      "execution_order": 1,
      "columns": [
        {
          "source": "email",
          "target": "email",
          "transforms": ["trim", "lower"]
        },
        {
          "source": "nome",
          "target": "name",
          "transforms": ["trim"]
        }
      ],
      "options": {
        "unique_key": "email",
        "duplicate_strategy": "update"
      }
    }
  ]
}
```

### 2. Run the Import

```bash
php artisan inflow:process file.csv --mapping=mapping.json
```

## Complete Example

```bash
# 1. Create mapping interactively
php artisan inflow:make-mapping users.csv App\\Models\\User
# This creates: mappings/User.json

# 2. Import
php artisan inflow:process users.csv --mapping=mappings/User.json
```

## Implementation Status

### Currently Available

- `inflow:make-mapping` - Interactive mapping creation
- `inflow:process` - ETL execution with mapping file
- Nested relations support
- Transformations
- Duplicate handling strategies
- Pivot sync for many-to-many relations

### Planned Features

- `inflow:analyze` - Standalone file analysis command
- `inflow:validate` - Standalone mapping validation command
- MCP server integration for AI-assisted configuration
- Complete `flow_config` automation support

## Next Steps

- Read [Architecture](architecture.md) to understand how it works
- Consult [Mapping JSON Schema](mapping-json-schema.md) for complete details
- See [Workflow](workflow.md) for advanced scenarios
