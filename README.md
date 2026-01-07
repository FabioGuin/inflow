# Laravel InFlow

[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)

**Dev-first ETL engine for Laravel** - Easily import data from CSV, Excel, JSON, and XML into your Eloquent models.

## Features

### Implemented

- **Multi-format support**: CSV, Excel (XLSX), JSON, JSON Lines, and XML
- **Declarative mapping**: JSON-based configuration to map columns to models
- **Nested relations**: Automatic handling of complex Eloquent relationships (BelongsTo, HasMany, HasOne, BelongsToMany)
- **Transformations**: Flexible data transformation system (trim, lower, upper, cast, etc.)
- **Performance**: Chunked processing for large files
- **Execution order**: Automatic calculation of model dependency order
- **Duplicate handling**: Strategies for handling duplicate records (error, skip, update)
- **Pivot sync**: Support for many-to-many relation synchronization
- **File sanitization**: BOM removal, newline normalization, control character removal
- **Format detection**: Automatic detection of file formats
- **Data profiling**: Quality analysis and statistics

### Planned / In Development

- **MCP-First integration**: Model Context Protocol server for assisted configuration
- **Standalone analysis command**: `inflow:analyze` to generate analysis.json
- **Standalone validation command**: `inflow:validate` for mapping validation
- **Complete flow_config**: Full automation support for recurring imports
- **Custom transform registration**: Plugin system for custom transformations
- **Circular dependency resolution**: Automatic handling of circular model dependencies

## Installation

### Via Composer

```bash
composer require fabio-guin/laravel-inflow
```

### Local Development

To contribute or test in local development:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/inflow"
        }
    ],
    "require": {
        "fabio-guin/laravel-inflow": "@dev"
    }
}
```

## Quick Start

### 1. Create the Mapping

Use the interactive command to create a mapping:

```bash
php artisan inflow:make-mapping path/to/file.csv App\\Models\\User
```

Or create a `mapping.json` file manually:

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

## Documentation

Complete documentation is available in the package's [docs folder](docs/):

- **[Getting Started](docs/getting-started.md)** - Quick guide
- **[Architecture](docs/architecture.md)** - How the system works
- **[Mapping JSON Schema](docs/mapping-json-schema.md)** - Complete mapping schema
- **[Workflow](docs/workflow.md)** - Advanced workflows
- **[Nested Relations](docs/nested-relations.md)** - Handling complex relationships
- **[Genericity Analysis](docs/technical/etl-genericity-analysis.md)** - Technical analysis of genericity

## Available Commands

### Implemented

- `inflow:process` - Run the import using a mapping file
- `inflow:make-mapping` - Create or configure a mapping file interactively
- `inflow:test-execution-order` - Test execution order calculation (development)
- `inflow:test-model-dependency` - Test model dependency analysis (development)

### Planned

- `inflow:analyze` - Analyze a file and generate `analysis.json`
- `inflow:validate` - Validate a mapping against an analysis

## Examples

### Import with Relations

```json
{
  "mappings": [
    {
      "model": "App\\Models\\Author",
      "execution_order": 1,
      "columns": [
        {"source": "author_name", "target": "name"}
      ]
    },
    {
      "model": "App\\Models\\Book",
      "execution_order": 2,
      "columns": [
        {"source": "title", "target": "title"},
        {"source": "author_name", "target": "author.name"}
      ]
    }
  ]
}
```

### Nested Array (JSON)

```json
{
  "columns": [
    {"source": "books", "target": "books.*.title"},
    {"source": "books", "target": "books.*.isbn"}
  ]
}
```

### Pivot Sync (Many-to-Many)

```json
{
  "model": "App\\Models\\Tag",
  "execution_order": 3,
  "type": "pivot_sync",
  "relation_path": "App\\Models\\Book.tags",
  "columns": [
    {"source": "book_isbn", "target": "book.isbn"},
    {"source": "tag_slug", "target": "tag.slug"}
  ]
}
```

## Testing

```bash
# From Laravel app root using Sail
./vendor/bin/sail exec laravel.test php packages/inflow/vendor/bin/phpunit --configuration packages/inflow/phpunit.xml

# Or from the package directory
cd packages/inflow
./vendor/bin/phpunit
```

## Requirements

- PHP 8.2+
- Laravel 12.0+
- Extensions: `fileinfo`, `libxml`, `simplexml`

## Contributing

Contributions are welcome! To contribute:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This package is open-source and released under the [Apache License 2.0](LICENSE).

## Status

**Active development** - Phase 0

The package is under active development. APIs and features may change.

**Current Implementation Status:**
- Core ETL processing: Implemented
- Mapping system: Implemented
- Relation handling: Implemented
- Transformations: Implemented
- MCP integration: Planned
- Standalone analysis/validation: Planned

## Useful Links

- [Complete Documentation](docs/)
- [Issue Tracker](https://github.com/fabio-guin/laravel-inflow/issues)
- [Laravel Documentation](https://laravel.com/docs)
