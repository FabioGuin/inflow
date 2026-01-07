# Laravel InFlow - Documentation

Laravel InFlow is an ETL (Extract, Transform, Load) package for Laravel that simplifies importing data from files (CSV, Excel, JSON, XML) into Eloquent models.

## Philosophy

**MCP-First, Minimal Commands**: The system is designed with an MCP (Model Context Protocol) server as the central point for mapping configuration, while CLI commands are pure executors without interactive logic.

## Documentation

### Getting Started

- **[Getting Started](getting-started.md)** - Quick start guide
- **[Architecture](architecture.md)** - How the MCP-first system works
- **[Workflow](workflow.md)** - Complete workflow

### Guides

- **[CLI Commands Usage](usage.md)** - How to use CLI commands
- **[Mapping JSON Schema](mapping-json-schema.md)** - Complete documentation of the mapping.json format
- **[Nested Relations](nested-relations.md)** - Approach for handling nested relations

### Technical

- **[Custom Transforms](technical/custom-transforms.md)** - Create custom transformations
- **[Relation-Driven Analysis](technical/relation-driven-analysis.md)** - Relation-driven approach analysis
- **[ETL Genericity Analysis](technical/etl-genericity-analysis.md)** - ETL engine genericity analysis

### Specifications

- **[ETL Engine Specification](specification/etl-engine-specification.md)** - Technical specifications of components

### Archive

Historical and obsolete documents are in `archive/`.

## Architecture

The system is organized in three distinct phases:

1. **Analysis** (`inflow:analyze`) - Analyzes files and generates `analysis.json`
2. **Configuration** (MCP or manual) - Creates `mapping.json` with column to model mappings
3. **Execution** (`inflow:process`) - Executes import using `mapping.json`

## Main Components

- **MCP Server** - Tools for analysis, suggestions, building and validating mappings
- **CLI Commands** - Pure executors without interactive logic
- **Mapping JSON** - Complete configuration file with `flow_config` for automated executions

## Conventions

- Files in `kebab-case.md`
- Relative links for internal references
- Practical examples in every guide
