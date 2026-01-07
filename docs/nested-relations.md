# Nested Relations Approach

## Current Problem

**Real client scenario:**
```
CSV/JSON/XML file with:
- author_name, author_email
- book_title, book_isbn (repeated for each book)
- tag_name, tag_slug (repeated for each tag of each book)
```

**Current system limitations:**
- Handles **2 levels**: Main model → Direct relation
  - E.g.: `Author` → `books.*` (HasMany)
  - E.g.: `Book` → `tags.*` (BelongsToMany)
- Does **NOT handle 3+ levels**: Model → Relation → Nested relation
  - E.g.: `Author` → `books.*` → `tags.*` (doesn't work)

**Why it's complex:**
- When we create `Book` from `Author`, the `tags` inside `books` are not processed
- We should process nested relations recursively
- Increases complexity, edge cases, debugging difficulty

---

## Proposed Solution: **Sequential Multiple Mappings**

### Base Concept

**One file, multiple passes, explicit order**

Instead of processing everything at once, we process the same file multiple times with different mappings, in dependency order.

### Practical Example

**Client file** (`authors_books_tags.csv`):
```csv
author_name,author_email,book_title,book_isbn,tag_name,tag_slug
John Doe,john@email.com,Book 1,978-123,fiction,fiction
John Doe,john@email.com,Book 1,978-123,adventure,adventure
John Doe,john@email.com,Book 2,978-456,sci-fi,sci-fi
```

**Mapping 1** (Order: 1) - `App\Models\Author`:
```json
{
  "execution_order": 1,
  "model": "App\\Models\\Author",
  "columns": [
    {"source": "author_name", "target": "name"},
    {"source": "author_email", "target": "email"}
  ],
  "options": {
    "unique_key": "email",
    "duplicate_strategy": "update"
  }
}
```

**Mapping 2** (Order: 2) - `App\Models\Book`:
```json
{
  "execution_order": 2,
  "model": "App\\Models\\Book",
  "columns": [
    {"source": "book_title", "target": "title"},
    {"source": "book_isbn", "target": "isbn"},
    {"source": "author_email", "target": "author.email+", "relation_lookup": {"field": "email"}}
  ],
  "options": {
    "unique_key": "isbn",
    "duplicate_strategy": "update"
  }
}
```

**Mapping 3** (Order: 3) - `App\Models\Tag` (pivot sync):
```json
{
  "execution_order": 3,
  "type": "pivot_sync",
  "relation": "App\\Models\\Book.tags",
  "columns": [
    {"source": "book_isbn", "target": "book.isbn", "relation_lookup": {"field": "isbn"}},
    {"source": "tag_slug", "target": "tag.slug+", "relation_lookup": {"field": "slug", "create_if_missing": true}}
  ]
}
```

### Advantages

- **Simplicity**: Each mapping is independent and simple
- **Clarity**: Order is explicit (`execution_order`)
- **Easy debugging**: Each pass is verifiable
- **Flexibility**: User can process only some passes
- **100% agnostic**: No automatic deduction
- **Scalable**: Works with any nesting level

### Implementation

**MappingDefinition structure:**
```php
class MappingDefinition {
    public array $mappings; // Array of ModelMapping with execution_order
}
```

**FlowExecutor:**
```php
// 1. Sort mappings by execution_order
$sortedMappings = $this->sortMappingsByOrder($mappingDefinition->mappings);

// 2. Process each mapping sequentially
foreach ($sortedMappings as $mapping) {
    $this->processMapping($source, $mapping);
}
```

**For pivot_sync:**
- Special mapping type that doesn't create models
- Only synchronizes many-to-many relations
- Explicit lookup for both entities

---

## Alternatives Considered

### 1. **Explicit Flattening**
- Ask client to provide already flat files
- **Problem**: Client doesn't want to modify their files

### 2. **Recursive Processing**
- Process nested relations recursively
- **Problem**: Exponential complexity, difficult to debug

### 3. **Multi-file**
- Ask client for separate files for each entity
- **Problem**: Client has a single file

### 4. **Sequential Multiple Mappings**
- **Advantage**: Simple, clear, flexible
- **Disadvantage**: Requires explicit configuration (but this is an advantage for clarity)

---

## Implementation Plan

### Phase 1: Extend MappingDefinition
- Add `execution_order` to `ModelMapping`
- Support `type: "pivot_sync"` for many-to-many relations

### Phase 2: Modify FlowExecutor
- Sort mappings by `execution_order`
- Process sequentially
- Handle `pivot_sync` as special case

### Phase 3: UI/UX
- During interactive mapping, ask for `execution_order`
- Validate that order respects dependencies
- Show preview of execution order

---

## Open Questions

1. **Dependency validation**: Should we automatically validate that the order is correct?
   - E.g.: If `Book` has `author.email+`, `Author` must be order 1

2. **Pivot data**: How do we handle pivot data (e.g., `book_tag.created_at`)?
   - We could add `pivot_*` columns in the mapping

3. **Performance**: Is processing the same file N times acceptable?
   - Yes, for ETL files it's normal (not real-time)

4. **Backward compatibility**: Existing mappings without `execution_order`?
   - Default: `execution_order: 1` (current behavior)

---

## Recommendation

**Implement "Sequential Multiple Mappings"** because:
- Solves the problem in a simple and clear way
- Doesn't require over-engineering
- User has total control
- Easy to explain and document
- Scalable to any nesting level

**Guiding principle**: **KISS** - Keep It Simple, Stupid

