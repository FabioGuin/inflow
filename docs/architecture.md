# Laravel InFlow Architecture

## Overview

Laravel InFlow follows an **MCP-First** architecture with clear separation between configuration and execution.

## Design Principles

### 1. Configuration/Execution Separation

- **Configuration**: Managed by interactive commands or manually (mapping.json)
- **Execution**: Pure CLI commands, zero interactive logic during processing

### 2. MCP-First (Planned)

The MCP server is planned as the central point for:
- File analysis (`inflow_analyze_file`) - Planned
- Mapping suggestions (`inflow_suggest_mapping`) - Planned
- Mapping construction (`inflow_build_mapping`) - Planned
- Validation (`inflow_validate_mapping`) - Planned

### 3. Complete Mapping JSON

The `mapping.json` file contains:
- Column to model mappings
- `flow_config` for automated executions (partial support)
- Everything needed for recurring executions

## Main Components

### CLI Commands (Implemented)

**Minimal, without interactive logic during execution:**

1. **`inflow:process`** (Implemented)
   - Input: file, `mapping.json` (required)
   - Output: import results
   - Uses: `ETLOrchestrator`

2. **`inflow:make-mapping`** (Implemented)
   - Input: file, optional model class
   - Output: `mapping.json`
   - Uses: `MappingOrchestrator`

3. **`inflow:test-execution-order`** (Development)
   - Tests execution order calculation
   - Uses: `ExecutionOrderService`

4. **`inflow:test-model-dependency`** (Development)
   - Tests model dependency analysis
   - Uses: `ModelDependencyService`

### Planned Commands

1. **`inflow:analyze`** (Planned)
   - Input: file path
   - Output: `analysis.json`
   - Will use: `FileAnalysisService`

2. **`inflow:validate`** (Planned)
   - Input: `mapping.json`, optional `analysis.json`
   - Output: validation with errors/warnings
   - Will use: `ValidateMappingService`

### MCP Server (Planned)

**File**: `src/Mcp/InFlowMcpServer.php` - Not yet implemented

Planned to expose 4 tools:
- `AnalyzeFileTool` - Analyze files
- `SuggestMappingTool` - Suggest mappings
- `BuildMappingTool` - Build mapping from responses
- `ValidateMappingTool` - Validate mapping

### Core Services

#### ETLOrchestrator (Implemented)
- Orchestrates the complete ETL process
- Handles file reading, sanitization, format detection
- Processes mappings in execution order
- Manages nested relations and pivot sync

#### MappingOrchestrator (Implemented)
- Interactive mapping creation
- Column mapping suggestions
- Relation handling
- Transform selection

#### ExecutionOrderService (Implemented)
- Calculates execution order using topological sort
- Based on `BelongsTo` dependency analysis
- Works for any model hierarchy

#### ModelDependencyService (Implemented)
- Analyzes model dependencies using reflection
- Identifies relation types
- Builds dependency graph

#### RelationTypeService (Implemented)
- Determines relation type dynamically
- Supports BelongsTo, HasOne, HasMany, BelongsToMany

#### EloquentLoader (Implemented)
- Loads data into Eloquent models
- Handles relations (BelongsTo, HasOne, HasMany)
- Supports duplicate strategies (error, skip, update)
- Processes nested array mappings

#### PivotSyncService (Implemented)
- Handles `pivot_sync` type mappings
- Syncs many-to-many relations without creating models
- Supports pivot data

#### TransformEngine (Implemented)
- Applies transformations to data
- Built-in transforms: trim, lower, upper, cast, etc.
- Extensible for custom transforms

#### FormatDetector (Implemented)
- Auto-detects file formats (CSV, Excel, JSON, XML)
- Analyzes file structure
- Provides format configuration

#### Profiler (Implemented)
- Profiles data quality
- Generates statistics
- Identifies anomalies

#### SanitizationService (Implemented)
- Removes BOM
- Normalizes newlines
- Removes control characters

### Planned Services

#### FileAnalysisService (Planned)
- Will analyze files using Profiler, FormatDetector, Sanitizer
- Will generate standardized `analysis.json`

#### MappingSuggestionService (Planned)
- Will suggest column to model attribute/relation mappings
- Will use `MappingSuggestionEngine` for heuristics

#### ValidateMappingService (Planned)
- Will validate structure, dependencies, schema, models
- Will use `MappingValidator`

#### BuildMappingService (Planned)
- Will build `MappingDefinition` from responses
- Will save as `mapping.json`

### Value Objects

Immutable data structures:
- `MappingDefinition` - Complete mapping with `flowConfig` (Implemented)
- `SourceSchema` - Source file schema (Implemented)
- `ModelMapping` - Mapping for a model (Implemented)
- `ColumnMapping` - Single column mapping (Implemented)
- `Flow` - Flow configuration (Partial - TODO: Re-implement with new mapping system)
- `FlowRun` - Execution result (Implemented)
- `Row` - Data row wrapper (Implemented)
- `ProcessingContext` - ETL processing context (Implemented)

### Base Components (Implemented)

- **Profilers/Profiler** - Data profiling
- **Detectors/FormatDetector** - Format detection
- **Sanitizers/RawSanitizer** - File sanitization
- **Readers/CsvReader, ExcelReader, JsonLinesReader, XmlReader** - File reading
- **Transforms/TransformEngine** - Transform application
- **Loaders/EloquentLoader** - Model loading

## Data Flow

### Phase 1: Mapping Creation (Implemented)

```
File → MappingOrchestrator → mapping.json
  ├─ FormatDetector (detects format)
  ├─ Profiler (profiles data)
  └─ Interactive prompts (column mapping)
```

### Phase 2: Execution (Implemented)

```
File + mapping.json → ETLOrchestrator → Database
  ├─ LoadMappingPipe (loads mapping)
  ├─ SanitizePipe (sanitizes if configured)
  ├─ DetectFormatPipe (detects format)
  ├─ ReadDataPipe (reads data)
  ├─ TransformPipe (applies transformations)
  └─ LoadPipe (loads into models)
```

### Phase 1: Analysis (Planned)

```
File → FileAnalysisService → analysis.json
  ├─ FormatDetector (detects format)
  ├─ RawSanitizer (sanitizes if needed)
  ├─ Reader (reads data)
  └─ Profiler (profiles data)
```

### Phase 2: Configuration (Planned - MCP)

```
analysis.json + Model → MCP Tools → mapping.json
  ├─ SuggestMappingTool (suggests)
  ├─ BuildMappingTool (builds)
  └─ ValidateMappingTool (validates)
```

## Service Provider

`InFlowServiceProvider` registers:
- Core services (ConfigurationResolver, FormatDetector, Profiler, etc.) - Implemented
- Mapping services (MappingOrchestrator, ExecutionOrderService, etc.) - Implemented
- Loading services (EloquentLoader, PivotSyncService, etc.) - Implemented
- CLI commands - Implemented
- ETL Orchestrator - Implemented

## Extensibility

### Adding Custom Transforms (Implemented)

See [Custom Transforms](technical/custom-transforms.md)

### Adding Custom Readers (Implemented)

Implement `ReaderInterface` and register in ServiceProvider.

### Adding MCP Tools (Planned)

Extend `Laravel\Mcp\Server\Tool` and register in `InFlowMcpServer::$tools`.

## Architecture Benefits

1. **Simplification**: Clear separation of configuration/execution
2. **MCP-First** (Planned): Interactive configuration via AI
3. **Automation**: `flow_config` allows recurring executions (partial)
4. **Testability**: Isolated and testable components
5. **Maintainability**: Minimal code, without legacy complexity

## Implementation Status

### Fully Implemented

- Core ETL processing pipeline
- Mapping creation (interactive)
- Relation handling (BelongsTo, HasOne, HasMany, BelongsToMany)
- Nested relations processing
- Pivot sync
- Transformations
- Execution order calculation
- Duplicate handling strategies
- File format support (CSV, Excel, JSON, XML)
- Sanitization
- Data profiling

### Partially Implemented

- `flow_config` - Basic support, needs completion
- Custom transforms - Basic support, needs plugin system

### Planned

- MCP server integration
- Standalone analysis command
- Standalone validation command
- Complete `flow_config` automation
- Circular dependency resolution
- Custom transform registration system
