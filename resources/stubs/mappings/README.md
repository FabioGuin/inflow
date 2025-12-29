# Mapping Files per Test Stubs

Questa directory contiene i file di mapping JSON corrispondenti agli stub di test.

## Struttura

I mapping sono organizzati nelle stesse sottocartelle degli stub:

```
mappings/
├── relations/          # Mapping per test relazioni
├── field_types/        # Mapping per test tipi campo
├── errors/             # Mapping per test errori
├── transforms/         # Mapping per test trasformazioni
├── edge_cases/         # Mapping per test casi limite
└── validation/         # Mapping per test validazione
```

## Convenzione di Nomenclatura

I file di mapping seguono la convenzione:
```
{stub_name}_{ModelClass}.json
```

Esempio:
- `belongs_to.csv` → `belongs_to_App_Models_Book.json`
- `author_rules.csv` → `author_rules_App_Models_Author.json`

## Uso nei Test

Quando si esegue un test, specificare il mapping con `--mapping`:

```bash
vendor/bin/sail artisan inflow:process \
  packages/inflow/resources/stubs/relations/belongs_to.csv \
  "App\\Models\\Book" \
  --mapping=packages/inflow/resources/stubs/mappings/relations/belongs_to_App_Models_Book.json \
  --no-interaction
```

## Mapping Disponibili

### Relations (4)
- `belongs_to_App_Models_Book.json` - BelongsTo relation via author_name lookup
- `has_one_App_Models_Author.json` - HasOne relation (Author → Profile)
- `has_many_App_Models_Author.json` - HasMany relation (Author → Books array)
- `belongs_to_many_App_Models_Book.json` - BelongsToMany relation (Book ↔ Tags with pivot)

### Field Types (2)
- `all_types_App_Models_Author.json` - All field types test
- `nullable_fields_App_Models_Author.json` - Nullable fields handling

### Errors (6)
- `type_mismatch_App_Models_Book.json` - Type mismatch errors
- `missing_required_App_Models_Author.json` - Missing required fields
- `invalid_fk_App_Models_Book.json` - Invalid foreign key lookups
- `duplicates_App_Models_Book.json` - Duplicate record handling
- `duplicate_author_App_Models_Book.json` - Duplicate author creation
- `relation_missing_required_App_Models_Book.json` - Relation creation with missing required
- `string_too_long_App_Models_Book.json` - String exceeding column length
- `unique_violation_App_Models_Book.json` - Unique constraint violation (error strategy)

### Transforms (5)
- `date_formats_App_Models_Book.json` - Date format parsing
- `string_transforms_App_Models_Author.json` - String transformations
- `numeric_transforms_App_Models_Book.json` - Numeric transformations
- `utility_transforms_App_Models_Author.json` - Utility transforms (coalesce, json, etc.)
- `case_transforms_App_Models_Book.json` - Case/formattazione transforms
- `complex_mapping_App_Models_Author.json` - Complex XML nested mapping

### Edge Cases (3)
- `empty_values_App_Models_Author.json` - Empty vs null handling
- `special_chars_App_Models_Author.json` - Special characters and unicode
- `large_text_App_Models_Author.json` - Large text fields

### Validation (4)
- `author_rules_App_Models_Author.json` - Author validation rules
- `book_rules_App_Models_Book.json` - Book validation rules
- `profile_rules_App_Models_Profile.json` - Profile validation rules
- `tag_rules_App_Models_Tag.json` - Tag validation rules

## Note

- I mapping includono validation rules complete per ogni campo
- Le relazioni sono gestite automaticamente dal sistema basandosi su execution_order e tipo di relazione
- I transform sono applicati dove necessario
- Le opzioni `duplicate_strategy` sono configurate per ogni test

