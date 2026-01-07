# Mapping JSON Schema - Complete Reference

## Version
1.0

## Base Structure

The `mapping.json` file defines how to map columns from a source file to Laravel Eloquent models, including transformations, relations, and execution options.

```json
{
  "version": "1.0",
  "name": "User Import Mapping",
  "description": "Mapping for importing users from CSV",
  "source_schema": { ... },
  "flow_config": { ... },
  "mappings": [ ... ]
}
```

## Main Fields

### `version` (string, required)
Schema version. Currently: `"1.0"`

### `name` (string, required)
Descriptive name of the mapping.

### `description` (string, optional)
Description of the mapping.

### `source_schema` (object, optional)
Source file schema (from `analysis.json`). Includes:
- `total_rows`: total number of rows
- `columns`: object with metadata for each column

### `flow_config` (object, optional)
Complete flow configuration for automated execution.

### `mappings` (array, required)
Array of `ModelMapping`, one for each model to import.

## flow_config

Configuration for automated execution (recurring, cron, etc.).

```json
{
  "flow_config": {
    "sanitizer": {
      "enabled": true,
      "remove_bom": true,
      "normalize_newlines": true,
      "remove_control_chars": true,
      "newline_format": "lf"
    },
    "format": {
      "type": "csv",
      "delimiter": ",",
      "quote_char": "\"",
      "has_header": true,
      "encoding": "UTF-8"
    },
    "execution": {
      "chunk_size": 1000,
      "error_policy": "continue",
      "skip_empty_rows": true,
      "truncate_long_fields": true
    }
  }
}
```

### sanitizer

- `enabled` (boolean): Enable file sanitization
- `remove_bom` (boolean): Remove UTF-8/UTF-16 BOM
- `normalize_newlines` (boolean): Normalize newlines (CRLF/LF/CR)
- `remove_control_chars` (boolean): Remove control characters
- `newline_format` (string): Final newline format (`lf`, `crlf`, `cr`)

### format

- `type` (string): File type (`csv`, `xlsx`, `xls`, `json`, `xml`)
- `delimiter` (string): CSV delimiter (`,`, `;`, `\t`, etc.)
- `quote_char` (string): Quote character (`"`, `'`)
- `has_header` (boolean): File has header
- `encoding` (string): File encoding (`UTF-8`, `ISO-8859-1`, etc.)

**Note**: If `format` is not specified, it is auto-detected.

### execution

- `chunk_size` (integer): Chunk size for processing (default: 1000)
- `error_policy` (string): `stop` (stop on first error) or `continue` (continue and collect errors)
- `skip_empty_rows` (boolean): Skip completely empty rows
- `truncate_long_fields` (boolean): Automatically truncate fields that are too long

## mappings

Array of `ModelMapping`. Each mapping defines how to import data into an Eloquent model.

### ModelMapping

```json
{
  "model": "App\\Models\\User",
  "execution_order": 1,
  "type": "model",
  "columns": [ ... ],
  "options": { ... }
}
```

#### ModelMapping Fields

- `model` (string, required): FQCN of the Eloquent model (e.g., `App\Models\User`)
- `execution_order` (integer, required): Execution order (1 = first, 2 = second, etc.)
- `type` (string, optional): Mapping type (`model` default, `pivot_sync` for many-to-many relations)
- `relation_path` (string, optional): For `pivot_sync`, relation path (e.g., `Book.tags`)
- `columns` (array, required): Array of `ColumnMapping`
- `options` (object, optional): Options for the mapping

#### options

```json
{
  "options": {
    "unique_key": "email",
    "duplicate_strategy": "update"
  }
}
```

- `unique_key` (string|array): Field(s) to identify duplicates
- `duplicate_strategy` (string): `error` (default), `skip`, `update`

### ColumnMapping

```json
{
  "source": "email_column",
  "target": "email",
  "transforms": ["trim", "lower"],
  "default": null,
  "validation_rule": "required|email",
  "relation_lookup": null
}
```

#### ColumnMapping Fields

- `source` (string, required): Column name in source file
- `target` (string, required): Field path in model (e.g., `email`, `address.street`, `author.name+`)
- `transforms` (array, optional): Array of transformations to apply
- `default` (mixed, optional): Default value if field is empty
- `validation_rule` (string, optional): Laravel validation rule
- `relation_lookup` (object, optional): Relation lookup configuration

#### target Path

The `target` can be:
- Direct field: `email`, `name`
- Nested relation: `author.name` (BelongsTo), `address.street` (HasOne)
- Relation with create: `author.name+` (the `+` indicates "create if missing")
- Array relation: `books.*.title` (HasMany)

#### relation_lookup

For relations (BelongsTo, HasOne, HasMany):

```json
{
  "relation_lookup": {
    "field": "name",
    "create_if_missing": true
  }
}
```

- `field` (string, required): Field of the related model for lookup
- `create_if_missing` (boolean, optional): Create record if not found (default: false)

For `pivot_sync`:

```json
{
  "relation_lookup": {
    "field": "slug",
    "create_if_missing": true
  }
}
```

## Complete Examples

### Example 1: Simple Mapping (User)

```json
{
  "version": "1.0",
  "name": "User Import",
  "description": "Import users from CSV",
  "flow_config": {
    "sanitizer": {
      "enabled": true,
      "remove_bom": true,
      "normalize_newlines": true,
      "newline_format": "lf"
    },
    "execution": {
      "chunk_size": 1000,
      "error_policy": "continue"
    }
  },
  "mappings": [
    {
      "model": "App\\Models\\User",
      "execution_order": 1,
      "columns": [
        {
          "source": "email",
          "target": "email",
          "transforms": ["trim", "lower"],
          "validation_rule": "required|email"
        },
        {
          "source": "nome",
          "target": "name",
          "transforms": ["trim"]
        },
        {
          "source": "eta",
          "target": "age",
          "transforms": ["cast:int"]
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

### Example 2: Mapping with Relation (Book → Author)

```json
{
  "version": "1.0",
  "name": "Book Import with Author",
  "mappings": [
    {
      "model": "App\\Models\\Author",
      "execution_order": 1,
      "columns": [
        {
          "source": "author_name",
          "target": "name",
          "transforms": ["trim"]
        },
        {
          "source": "author_email",
          "target": "email",
          "transforms": ["trim", "lower"]
        }
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
        {
          "source": "book_title",
          "target": "title",
          "transforms": ["trim"]
        },
        {
          "source": "book_isbn",
          "target": "isbn"
        },
        {
          "source": "author_email",
          "target": "author.email",
          "relation_lookup": {
            "field": "email"
          }
        }
      ],
      "options": {
        "unique_key": "isbn",
        "duplicate_strategy": "update"
      }
    }
  ]
}
```

### Example 3: Mapping with Pivot Sync (Book ↔ Tags)

```json
{
  "version": "1.0",
  "name": "Book Tags Sync",
  "mappings": [
    {
      "model": "App\\Models\\Book",
      "execution_order": 1,
      "columns": [
        {
          "source": "isbn",
          "target": "isbn"
        }
      ],
      "options": {
        "unique_key": "isbn"
      }
    },
    {
      "model": "App\\Models\\Tag",
      "execution_order": 2,
      "type": "pivot_sync",
      "relation_path": "App\\Models\\Book.tags",
      "columns": [
        {
          "source": "isbn",
          "target": "book.isbn",
          "relation_lookup": {
            "field": "isbn"
          }
        },
        {
          "source": "tag_slug",
          "target": "tag.slug",
          "relation_lookup": {
            "field": "slug",
            "create_if_missing": true
          }
        }
      ]
    }
  ]
}
```

### Example 4: Complete Mapping with flow_config

```json
{
  "version": "1.0",
  "name": "Product Catalog Nightly Import",
  "description": "Nightly product catalog import",
  "flow_config": {
    "sanitizer": {
      "enabled": true,
      "remove_bom": true,
      "normalize_newlines": true,
      "remove_control_chars": true,
      "newline_format": "lf"
    },
    "format": {
      "type": "csv",
      "delimiter": ",",
      "has_header": true,
      "encoding": "UTF-8"
    },
    "execution": {
      "chunk_size": 1000,
      "error_policy": "continue",
      "skip_empty_rows": true,
      "truncate_long_fields": true
    }
  },
  "mappings": [
    {
      "model": "App\\Models\\Product",
      "execution_order": 1,
      "columns": [
        {
          "source": "sku",
          "target": "sku",
          "transforms": ["trim", "upper"]
        },
        {
          "source": "nome_prodotto",
          "target": "name",
          "transforms": ["trim"]
        },
        {
          "source": "prezzo",
          "target": "price",
          "transforms": ["cast:float"]
        },
        {
          "source": "categoria",
          "target": "category.name+",
          "transforms": ["trim"],
          "relation_lookup": {
            "field": "name",
            "create_if_missing": true
          }
        }
      ],
      "options": {
        "unique_key": "sku",
        "duplicate_strategy": "update"
      }
    }
  ]
}
```

## Available Transformations

### String
- `trim`: Remove leading/trailing spaces
- `lower`: Convert to lowercase
- `upper`: Convert to uppercase
- `capitalize`: First letter uppercase
- `slugify`: Convert to slug
- `truncate:50`: Truncate to 50 characters

### Numeric
- `cast:int`: Convert to integer
- `cast:float`: Convert to float
- `round:2`: Round to 2 decimals

### Date
- `cast:date`: Convert to date
- `date_format:Y-m-d`: Format date

### Utility
- `default:value`: Default value if empty
- `regex_replace:pattern:replacement`: Regex replacement
- `concat:field1,field2`: Concatenate fields

## Validation

The mapping can be validated with:
- `inflow:validate mapping.json` - Standalone validation
- Automatic validation in `inflow:process` before execution

## Notes

- `execution_order` must be unique for each mapping
- For relations, the parent model must have a lower `execution_order` than the child
- `flow_config` is optional: if missing, uses defaults or command options
- Mappings are executed in `execution_order` order
