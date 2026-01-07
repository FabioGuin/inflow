# ETL Genericity Analysis

## Generic Aspects (Agnostic)

### 1. **No Model Hardcoding**
- No hardcoded model names in code (only examples in comments)
- All models are passed as class strings (`$modelClass`)
- Relations are detected dynamically using reflection

### 2. **Dynamic Relation Detection**
- `ModelDependencyService` analyzes dependencies using reflection
- `RelationTypeService` determines relation type dynamically
- `injectForeignKeysFromCreatedModels` uses `getForeignKeyName()` to find foreign keys
- `processNestedMappings` verifies relations with `method_exists()` and reflection

### 3. **Generic Target Parsing**
- `parseTarget()` extracts attributes/relations from strings like `"name"`, `"profile.bio"`, `"books.*.title"`
- Uses generic regex to identify patterns (`/^([^.*]+)\.\*\./`)
- Does not assume specific column or relation names

### 4. **Execution Order Management**
- `ExecutionOrderService` calculates order using topological sort
- Based on `BelongsTo` dependency analysis, not hardcoded names
- Works for any model hierarchy

### 5. **Configuration-Driven Mapping**
- Everything is driven by JSON mapping, not hardcoded logic
- `execution_order`, `unique_key`, `duplicate_strategy` are configurable
- Supports any data structure defined in the mapping

---

## Assumptions and Limitations

### 1. **Array Mapping Structure**
**Assumption**: An array mapping must have all columns with the same `source`.

```php
// OK: all columns have source="books"
[
  {source: "books", target: "books.*.title"},
  {source: "books", target: "books.*.isbn"}
]

// NOT SUPPORTED: columns with different sources
[
  {source: "books", target: "books.*.title"},
  {source: "authors", target: "books.*.author_id"}  // ❌
]
```

**Impact**: Limits flexibility for complex arrays with data from multiple columns.

---

### 2. **Nested Mapping Patterns**
**Assumption**: Nested mappings must have targets with pattern `relation.*.attribute`.

```php
// OK: pattern recognized
{source: "books", target: "tags.*.name"}

// NOT RECOGNIZED: different pattern
{source: "books", target: "tags[].name"}  // ❌
{source: "books", target: "tags/name"}   // ❌
```

**Impact**: Only `relation.*.attribute` pattern is supported for nested relations.

---

### 3. **Foreign Key Injection Order**
**Assumption**: Foreign keys are injected in the correct order based on `execution_order`.

```php
// OK: Author (order 1) → Book (order 2) → Tag (order 3)
// author_id is injected into Book, book_id into Tag

// PROBLEM: If execution_order is wrong, foreign keys are missing
```

**Impact**: Requires that `execution_order` is calculated correctly. If wrong, foreign keys are missing.

---

### 4. **Relations Defined in Models**
**Assumption**: Relations must be correctly defined in Eloquent models.

```php
// OK: relation defined
class Book extends Model {
    public function author() { return $this->belongsTo(Author::class); }
}

// PROBLEM: if relation doesn't exist or wrong name
// processNestedMappings() silently skips
```

**Impact**: If a relation doesn't exist or has a different name, the nested mapping is ignored without error.

---

### 5. **JSON Array Structure**
**Assumption**: JSON arrays must be decodable and have consistent structure.

```php
// OK: array of objects
[{"title": "...", "isbn": "..."}, {...}]

// PROBLEM: different structure
{"book1": {...}, "book2": {...}}  // object instead of array
```

**Impact**: Only numeric arrays are supported, not objects with keys.

---

### 6. **Nested Relations in Sub-Row**
**Assumption**: Nested data must be accessible in `subRow` with the same name as the relation.

```php
// OK: subRow contains "tags" array
$subRow->get("tags")  // returns array of tags

// PROBLEM: if nested data has different name
$subRow->get("book_tags")  // not found
```

**Impact**: The column name in JSON must match the relation name in the model.

---

## Edge Cases Not Covered

### 1. **Multiple Sources for Array Mapping**
Not supported: mapping an array from multiple source columns.

```json
// NOT SUPPORTED
{
  "source": ["books", "book_metadata"],
  "target": "books.*.title"
}
```

### 2. **Nested Relations with Different Names**
Not supported: relation in model has different name from JSON column.

```php
// Model: public function bookTags() { ... }
// JSON: "tags" array
// Does not match automatically
```

### 3. **Circular Dependencies**
Detected but not handled: if there are circular dependencies, `ExecutionOrderService` detects them but doesn't resolve them.

```php
// DETECTED BUT NOT RESOLVED
Author → Book → Author (circular)
```

### 4. **Virtual Columns in Array Items**
Not supported: virtual (computed) columns inside array items.

```json
// NOT SUPPORTED
{
  "books": [{
    "title": "...",
    "_computed": "..."  // virtual column
  }]
}
```

---

## Genericity Assessment

| Aspect | Generic? | Notes |
|--------|----------|-------|
| Model names | 100% | No hardcoding |
| Relation names | 95% | `relation.*.attribute` pattern required |
| Data structure | 90% | Numeric arrays supported, objects not |
| Foreign keys | 95% | Auto-detected, require correct order |
| Execution order | 100% | Calculated dynamically |
| Mapping config | 100% | Fully configurable |
| Edge cases | 70% | Some cases not covered |

**Genericity Score: ~92%**

---

## Recommendations for Greater Genericity

### 1. **Alternative Pattern Support**
Add support for different patterns:
- `relation[].attribute`
- `relation/attribute`
- Configurable in mapping

### 2. **Multiple Sources for Arrays**
Allow array mappings from multiple columns:
```json
{
  "sources": ["books", "metadata"],
  "target": "books.*.title"
}
```

### 3. **Relation Name → JSON Column Mapping**
Allow name override:
```json
{
  "source": "tags",
  "target": "bookTags.*.name",  // relation "bookTags" but column "tags"
  "relation_override": {"bookTags": "tags"}
}
```

### 4. **Circular Dependency Handling**
Implement strategy for circular dependencies:
- Warning + suggestion of `unique_key` to break cycle
- Or explicit handling with manual `execution_order`

### 5. **Mapping Validation**
Add mapping validation before execution:
- Verify that all relations exist
- Verify that `execution_order` is valid
- Warnings for potential problems

---

## Conclusion

The implementation is **highly generic** (~92%) and works for any Laravel model and relation structure, provided:

1. Relations are correctly defined in models
2. Mapping follows supported patterns (`relation.*.attribute`)
3. `execution_order` is calculated correctly
4. JSON data are numeric arrays (not objects)

Main limitations are:
- Rigid target patterns (only `relation.*.attribute`)
- Array mappings from single column
- Relation name must match JSON column

These limitations are **reasonable** for an ETL and cover most real-world use cases.
