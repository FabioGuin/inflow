# Mapping Template - Guida Completa

Questo file (`mapping-template.json`) è un template completo che mostra tutte le opzioni disponibili per creare un mapping InFlow ETL.

## Filosofia "Model-First" ⭐

**Principio fondamentale**: Il modello Eloquent è la fonte di verità per trasformazioni e validazioni. L'ETL si adatta al modello, non viceversa.

### Quando usare Cast, Accessor e Mutator nel Modello

✅ **PREFERISCI SEMPRE** definire la logica di trasformazione nel modello:

```php
// ✅ CORRETTO: Usa cast e mutator nel modello
class Product extends Model
{
    protected $casts = [
        'price' => 'decimal:2',        // Cast automatico
        'is_active' => 'boolean',       // Cast automatico
        'published_at' => 'date',      // Cast automatico
        'metadata' => 'array',          // Cast automatico
    ];
    
    // Mutator per normalizzazione
    public function setEmailAttribute($value)
    {
        $this->attributes['email'] = strtolower(trim($value));
    }
    
    // Mutator per slug
    public function setSlugAttribute($value)
    {
        $this->attributes['slug'] = Str::slug($value);
    }
}
```

```json
// ✅ CORRETTO: Mapping minimale - solo pulizia base
{
  "source": "price_str",
  "target": "price"
  // NO "cast:decimal:2" - il modello lo gestisce già
},
{
  "source": "email_raw",
  "target": "email"
  // NO "trim", "lower" - il mutator lo gestisce già
}
```

### Quando usare Trasformatori nel Mapping

❌ **EVITA** trasformazioni nel mapping quando il modello può gestirle:

- ❌ `cast:decimal:2` se il modello ha già `'price' => 'decimal:2'`
- ❌ `cast:bool` se il modello ha già `'is_active' => 'boolean'`
- ❌ `cast:date` se il modello ha già `'published_at' => 'date'`
- ❌ `trim`, `lower` se il modello ha già un mutator che lo fa

✅ **USA** trasformatori solo per:

1. **Trasformazioni ETL-specifiche**: `json_decode` per convertire stringhe JSON in array prima di mappare a relazioni
2. **Trasformazioni cross-field**: `concat(first_name, " ", last_name)` per combinare più colonne sorgente
3. **Trasformazioni condizionali complesse**: che richiedono dati esterni o logica specifica per l'import
4. **Pulizia base**: `trim` per rimuovere spazi bianchi (se non gestito dal mutator)

### Vantaggi dell'approccio Model-First

- ✅ **DRY**: Logica di trasformazione in un solo posto (il modello)
- ✅ **Coerenza**: Stessa trasformazione per ETL, API, form, ecc.
- ✅ **Manutenibilità**: Cambi la logica in un solo posto
- ✅ **Best Practice Laravel**: Allineato con le convenzioni Laravel
- ✅ **Riusabilità**: Trasformazioni disponibili ovunque usi il modello

### Quando usare Default Values

✅ **PREFERISCI SEMPRE** definire i default nella migration (fonte di verità per il database):

```php
// ✅ CORRETTO: Default nella migration
Schema::create('products', function (Blueprint $table) {
    $table->decimal('price', 10, 2)->default(0);
    $table->boolean('is_active')->default(true);
    $table->string('status')->default('pending');
});
```

```json
// ✅ CORRETTO: Mapping senza default - il DB lo gestisce già
{
  "source": "price_str",
  "target": "price"
  // NO "default: 0" - la migration ha già ->default(0)
},
{
  "source": "active",
  "target": "is_active"
  // NO "default: true" - la migration ha già ->default(true)
}
```

❌ **EVITA** default nel mapping quando già definiti nella migration:

- ❌ `"default": 0.0` se la migration ha già `->default(0)`
- ❌ `"default": true` se la migration ha già `->default(true)`
- ❌ `"default": "pending"` se la migration ha già `->default('pending')`

✅ **USA** default nel mapping solo come fallback:

1. **Campo required senza default nel DB**: Se il campo è `required` ma non ha default nella migration
2. **Default ETL-specifico**: Valore diverso per l'import rispetto al normale uso
3. **Campo nullable**: Se vuoi un default specifico per l'ETL anche se il campo è nullable

**Priorità per Default Values**:
1. **Migration** (fonte di verità per il database) - `->default(value)`
2. **Modello** (se definito via `$attributes` o mutator) - raro
3. **Mapping** (solo fallback se non definito altrove) - `"default": value`

### Quando usare Validation Rules

✅ **PREFERISCI SEMPRE** definire le regole di validazione nel modello:

```php
// ✅ CORRETTO: Validation rules nel modello
class Product extends Model
{
    public static function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:100',
            'price' => 'required|numeric|min:0|max:9999.99',
            'email' => 'required|email|unique:products,email',
            'published_at' => 'nullable|date|before_or_equal:today',
            'is_active' => 'boolean',
        ];
    }
}
```

```json
// ✅ CORRETTO: Mapping senza validation_rule - il modello le gestisce già
{
  "source": "name",
  "target": "name"
  // NO "validation_rule" - il modello ha già rules()['name']
},
{
  "source": "price_str",
  "target": "price"
  // NO "validation_rule" - il modello ha già rules()['price']
}
```

❌ **EVITA** validation_rule nel mapping quando già definite nel modello:

- ❌ `"validation_rule": "required|string|min:2|max:100"` se il modello ha già `rules()['name']`
- ❌ `"validation_rule": "required|numeric|min:0"` se il modello ha già `rules()['price']`
- ❌ `"validation_rule": "required|email"` se il modello ha già `rules()['email']`

✅ **USA** validation_rule nel mapping solo come fallback:

1. **Regole ETL-specifiche**: Validazione diversa per l'import rispetto al normale uso
2. **Modello senza rules()**: Se il modello non ha metodo `rules()` o FormRequest
3. **Override temporaneo**: Per test o import specifici con regole diverse

**Priorità per Validation Rules**:
1. **Modello** (fonte di verità) - `rules()` statico o FormRequest
2. **Mapping** (solo fallback se non definito nel modello) - `"validation_rule": "..."`

### Rilevamento Automatico

Il sistema ETL rileva automaticamente:
- **Cast e mutator** del modello e suggerisce di rimuovere trasformazioni ridondanti
- **Default del database** (dalla migration) e suggerisce di rimuovere default ridondanti nel mapping
- **Validation rules** del modello (da `rules()`) e suggerisce di rimuovere validation_rule ridondanti nel mapping

## Struttura del File

### Campi Principali

- **`version`** (opzionale): Versione del formato mapping (es. "1.0")
- **`name`** (obbligatorio): Nome del mapping
- **`description`** (opzionale): Descrizione del mapping
- **`source_schema`** (opzionale): Schema dei dati sorgente (utile per validazione)
- **`flow_config`** (opzionale): Configurazione del flusso ETL
- **`mappings`** (obbligatorio): Array di mapping per i modelli
- **`created_at`** / **`updated_at`** (opzionali): Timestamp di creazione/aggiornamento

### Source Schema

Descrive la struttura dei dati sorgente. Il campo `type` può essere usato per **override manuale** del tipo rilevato automaticamente dal Profiler.

**Tipi disponibili**: `string`, `int`, `float`, `decimal`, `date`, `timestamp`, `time`, `bool`, `email`, `url`, `phone`, `ip`, `uuid`, `json`

```json
{
  "columns": {
    "column_name": {
      "name": "column_name",
      "type": "string|int|float|decimal|date|timestamp|time|bool|email|url|phone|ip|uuid|json",
      "null_count": 0,
      "unique_count": 10,
      "min": null,
      "max": null,
      "examples": ["value1", "value2"]
    }
  },
  "total_rows": 100
}
```

**Type Override**: Se il Profiler rileva un tipo errato (es. "1"/"0" come `int` invece di `bool`), puoi specificare manualmente il tipo corretto nel `source_schema`. Quando il mapping viene caricato, il tipo specificato nel `source_schema` ha priorità sul tipo rilevato automaticamente.

**Esempio di override**:
```json
{
  "source_schema": {
    "columns": {
      "is_active": {
        "name": "is_active",
        "type": "bool",
        "examples": ["1", "0"]
      }
    }
  }
}
```

### Flow Config

Configurazione del flusso ETL (opzionale).

**Principio di Priorità:**
- **JSON come fonte di verità**: Se il file JSON del mapping esiste e contiene `flow_config`, questi valori hanno priorità assoluta e vengono usati per l'esecuzione.
- **Config come fonte di verità (wizard manuale)**: Quando crei un mapping per la prima volta tramite wizard manuale (CLI, MCP, ecc.) e il file JSON non esiste ancora, i valori dal file `config/inflow.php` vengono usati come default. Questo evita di dover definire ogni opzione manualmente durante la configurazione iniziale.
- **Fallback**: Se un valore specifico manca nel JSON ma il JSON esiste, viene usato il valore dal config come fallback.

**Esempio pratico:**
1. **Prima volta (wizard)**: Non hai ancora `mappings/App_Models_User.json` → usa `config/inflow.php` come default
2. **Esecuzione successiva**: Hai `mappings/App_Models_User.json` con `flow_config` → usa i valori dal JSON
3. **Valore mancante**: JSON esiste ma `flow_config.execution.chunk_size` manca → usa `config/inflow.php` come fallback

```json
{
  "sanitizer": {
    "enabled": true,
    "remove_bom": true,
    "normalize_newlines": true,
    "remove_control_chars": true,
    "newline_format": "lf|crlf|cr"
  },
  "format": {
    "type": "csv|excel|json|xml",
    "delimiter": ",",
    "quote_char": "\"",
    "has_header": true,
    "encoding": "UTF-8"
  },
  "execution": {
    "chunk_size": 1000,
    "error_policy": "continue|stop",
    "skip_empty_rows": true,
    "truncate_long_fields": true,
    "preview_rows": 5
  }
}
```

#### Opzioni di Execution

- **`chunk_size`**: Dimensione del chunk per il processing (default: 1000)
- **`error_policy`**: Politica di gestione errori:
  - `"stop"`: Ferma l'esecuzione al primo errore
  - `"continue"`: Continua l'esecuzione raccogliendo gli errori
- **`skip_empty_rows`**: Salta le righe vuote durante l'import (default: true)
- **`truncate_long_fields`**: Tronca i campi stringa che superano la lunghezza massima della colonna (default: true)
- **`preview_rows`**: Numero di righe da mostrare nel preview durante la lettura del file (default: 5)

### Mappings

Array di mapping per ogni modello da importare:

```json
{
  "model": "App\\Models\\ModelClass",
  "execution_order": 1,
  "type": "model|pivot_sync",
  "columns": [...],
  "options": {
    "unique_key": "field_name|[\"field1\", \"field2\"]",
    "duplicate_strategy": "update|skip|error"
  }
}
```

**⚠️ IMPORTANTE - execution_order**:
- **Modello root**: `execution_order: 1` (senza BelongsTo o con BelongsTo nullable)
- **Modelli dipendenti**: `execution_order` maggiore del modello a cui appartengono
- **Regola**: Se `Book` ha BelongsTo `Author`, e `Author` è `execution_order: 1`, allora `Book` deve essere `execution_order: 2` o maggiore

### Column Mapping

Ogni colonna può avere:

- **`source`** (obbligatorio): Nome della colonna sorgente
- **`target`** (obbligatorio): Path di destinazione (es. "name", "author.email", "books.*.title")
- **`transforms`** (opzionale): Array di trasformazioni da applicare
- **`default`** (opzionale): Valore di default se la colonna è vuota. ⚠️ **Preferisci default nella migration** - usa questo solo come fallback se non definito nel DB.
- **`validation_rule`** (opzionale): Regola di validazione Laravel. ⚠️ **Preferisci rules() nel modello** - usa questo solo come fallback se non definito nel modello.

#### Transforms Disponibili

**⚠️ IMPORTANTE**: Usa transforms solo per casi specifici ETL. Preferisci sempre cast, accessor e mutator nel modello.

**Trasformazioni disponibili**:

- **Pulizia base**: `trim` (rimuove spazi bianchi - usa solo se non gestito dal mutator)
- **String**: `lower`, `upper`, `title`, `slugify`, `truncate:N` (preferisci mutator nel modello)
- **Numeric**: `cast:int`, `cast:float`, `cast:decimal:N` (⚠️ preferisci `$casts` nel modello)
- **Date**: `cast:date`, `date_format:Y-m-d` (⚠️ preferisci `$casts` nel modello)
- **Timestamp**: `cast:datetime`, `date_format:Y-m-d H:i:s` (⚠️ preferisci `$casts` nel modello)
- **Time**: `cast:time`, `date_format:H:i:s` (⚠️ preferisci `$casts` nel modello)
- **Boolean**: `cast:bool` (⚠️ preferisci `$casts` nel modello)
- **JSON**: `json_decode` (✅ valido - necessario per convertire stringa JSON in array prima di mappare a relazioni)
- **Array/String**: `split:delimiter` (✅ valido - trasformazione ETL-specifica)
- **Cross-field**: `concat:field1,\" \",field2` (✅ valido - combina più colonne sorgente)
- **Custom**: Trasformazioni personalizzate registrate in config (✅ valide per logica complessa)

**Note sui tipi**:
- **JSON**: Le colonne di tipo `json` contengono stringhe JSON valide (array o oggetti). Usa `json_decode` per convertirle in array/oggetti PHP prima di mapparle a relazioni. Questo è un caso valido per transforms.
- **Decimal**: Distinto da `float`, viene rilevato quando i valori hanno tipicamente 2-4 decimali (valori monetari). **Preferisci** `'price' => 'decimal:2'` nel `$casts` del modello invece di `cast:decimal:2` nel mapping.
- **Timestamp**: Include sia data che ora. Distinto da `date` che contiene solo la data. **Preferisci** `'published_at' => 'datetime'` nel `$casts` del modello.
- **Time**: Contiene solo l'ora, senza data. **Preferisci** cast nel modello se possibile.

**Esempi di quando usare transforms**:

```json
// ✅ Caso valido: json_decode necessario per ETL
{
  "source": "books_json",
  "target": "books.*.title",
  "transforms": ["json_decode"]
}

// ✅ Caso valido: concat cross-field
{
  "source": "__concat",
  "target": "full_name",
  "transforms": ["concat:first_name,\" \",last_name"]
}

// ❌ Caso da evitare: cast ridondante
// Se il modello ha: protected $casts = ['price' => 'decimal:2'];
{
  "source": "price_str",
  "target": "price",
  "transforms": ["cast:decimal:2"]  // ❌ RIDONDANTE
}

// ✅ Caso corretto: solo pulizia base
{
  "source": "price_str",
  "target": "price"
  // Il modello gestisce già il cast
}
```

## Gestione Relazioni

### Strategia: Prima Campi Diretti, Poi Relazioni

**Principio fondamentale**: Mappa prima i campi diretti del modello root, poi le relazioni in ordine di dipendenza.

**Approccio semplificato**:
1. ✅ **Fase 1**: Campi diretti (senza relazioni) - sempre supportato
2. ✅ **Fase 2**: Relazioni semplici (HasOne, HasMany dopo genitore) - da implementare
3. ✅ **Fase 3**: BelongsTo (con lookup automatico) - da implementare
4. ✅ **Fase 4**: BelongsToMany (via pivot) - da implementare

### Determinare il Modello Root

Il modello root è quello **senza dipendenze BelongsTo** (o con BelongsTo nullable). Il sistema analizza automaticamente le dipendenze e suggerisce modelli root.

**Esempio**:
- ✅ **Author**: 0 BelongsTo → Root model (consigliato)
- ❌ **Book**: 1 BelongsTo (author) → Richiede Author prima
- ❌ **Profile**: 1 BelongsTo (author) → Richiede Author prima
- ✅ **Tag**: 0 BelongsTo → Root model (ma dipende da Book per relazione)

### Ordine di Esecuzione (execution_order)

I mapping vengono eseguiti in ordine crescente di `execution_order`:

1. **execution_order: 1** → Modello root (senza BelongsTo)
2. **execution_order: 2** → Modelli che dipendono da execution_order: 1
3. **execution_order: 3** → Modelli che dipendono da execution_order: 2
4. E così via...

**Regola**: Un modello con BelongsTo deve avere `execution_order` maggiore del modello a cui appartiene.

### Tipi di Relazione e Comportamento

#### HasOne (1:1)

**Esempio**: `Author → Profile`

```json
{
  "model": "App\\Models\\Author",
  "execution_order": 1,
  "columns": [
    { "source": "name", "target": "name" },
    { "source": "email", "target": "email" }
  ]
},
{
  "model": "App\\Models\\Profile",
  "execution_order": 2,
  "columns": [
    { "source": "profile_bio", "target": "profile.bio" },
    { "source": "profile_website", "target": "profile.website" }
  ]
}
```

**Comportamento**:
- Profile viene creato DOPO Author
- `author_id` viene impostato automaticamente
- Il target `profile.bio` indica relazione HasOne

#### HasMany (1:N)

**Esempio**: `Author → Books`

```json
{
  "model": "App\\Models\\Author",
  "execution_order": 1,
  "columns": [
    { "source": "name", "target": "name" }
  ]
},
{
  "model": "App\\Models\\Book",
  "execution_order": 2,
  "columns": [
    { 
      "source": "books_json", 
      "target": "books.*.title",
      "transforms": ["json_decode"]
    }
  ]
}
```

**Comportamento**:
- Books vengono creati DOPO Author
- `author_id` viene impostato automaticamente per ogni Book
- Il target `books.*.title` indica relazione HasMany
- Se `books` è JSON, usa `json_decode` per convertire in array

#### BelongsTo (N:1)

**Esempio**: `Book → Author`

```json
{
  "model": "App\\Models\\Author",
  "execution_order": 1,
  "columns": [
    { "source": "name", "target": "name" },
    { "source": "email", "target": "email" }
  ]
},
{
  "model": "App\\Models\\Book",
  "execution_order": 2,
  "columns": [
    { "source": "author_email", "target": "author.email" },
    { "source": "title", "target": "title" }
  ]
}
```

**Comportamento**:
- Book richiede Author esistente (execution_order: 1)
- Il target `author.email` indica lookup per email
- Il sistema risolve `author_id` automaticamente cercando Author per email
- Se Author non esiste → errore (o creazione se configurato)

#### BelongsToMany (N:N)

**Esempio**: `Book → Tags`

```json
{
  "model": "App\\Models\\Book",
  "execution_order": 2,
  "columns": [
    { "source": "title", "target": "title" }
  ]
},
{
  "model": "App\\Models\\Tag",
  "execution_order": 3,
  "columns": [
    { 
      "source": "tags_json", 
      "target": "tags.*.name",
      "transforms": ["json_decode"]
    }
  ]
}
```

**Comportamento**:
- Tag viene creato DOPO Book (execution_order: 3)
- Il target `tags.*.name` indica relazione BelongsToMany
- Il sistema crea Tag se non esiste (lookup per `name` o `slug`)
- Collega Book e Tag via pivot table automaticamente

### Target Paths

- **Campo diretto**: `"name"` → `$model->name`
- **HasOne**: `"profile.bio"` → `$model->profile->bio` (crea Profile dopo Author)
- **HasMany**: `"books.*.title"` → `$model->books[0]->title` (crea Books dopo Author)
- **BelongsTo**: `"author.email"` → lookup Author per email, imposta `author_id`
- **BelongsToMany**: `"tags.*.name"` → crea/collega Tag via pivot
- **Annidato**: `"books.*.tags.*.name"` → `$model->books[0]->tags[0]->name` (richiede ordine: Author → Book → Tag)

### Options

- **`unique_key`**: Campo o array di campi per identificare record duplicati
- **`duplicate_strategy`**: 
  - `"update"`: Aggiorna il record esistente
  - `"skip"`: Salta il record duplicato
  - `"error"`: Genera un errore

## Esempi di Uso

### Mapping Semplice

**Esempio con approccio Model-First**:

```php
// Modello: App\Models\Author
class Author extends Model
{
    protected $casts = [
        'is_active' => 'boolean',
    ];
    
    // Mutator normalizza email automaticamente
    public function setEmailAttribute($value)
    {
        $this->attributes['email'] = strtolower(trim($value));
    }
}
```

```json
{
  "name": "Simple Author Import",
  "mappings": [
    {
      "model": "App\\Models\\Author",
      "columns": [
        {
          "source": "name",
          "target": "name",
          "transforms": ["trim"]
        },
        {
          "_comment": "NO 'lower' qui - il mutator setEmailAttribute() lo gestisce già",
          "source": "email",
          "target": "email",
          "transforms": ["trim"]
        },
        {
          "_comment": "NO 'cast:bool' qui - il modello ha già 'is_active' => 'boolean'",
          "source": "active",
          "target": "is_active"
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

### Mapping con Relazioni - Esempio Completo

**Scenario**: Importare Author con Profile, Books e Tags da CSV con dati nested.

**Struttura CSV**:
```
name, email, country, profile.bio, profile.website, books
```

Dove `books` è JSON: `[{"title":"...","isbn":"...","tags":[{"name":"..."}]}]`

**Mapping**:

```json
{
  "name": "Author with Profile, Books and Tags",
  "mappings": [
    {
      "_comment": "Step 1: Modello root (0 BelongsTo) - campi diretti",
      "model": "App\\Models\\Author",
      "execution_order": 1,
      "columns": [
        { "source": "name", "target": "name" },
        { "source": "email", "target": "email" },
        { "source": "country", "target": "country" }
      ],
      "options": {
        "unique_key": "email",
        "duplicate_strategy": "update"
      }
    },
    {
      "_comment": "Step 2: HasOne - Profile viene creato dopo Author",
      "model": "App\\Models\\Profile",
      "execution_order": 2,
      "columns": [
        { "source": "profile.bio", "target": "profile.bio" },
        { "source": "profile.website", "target": "profile.website" }
      ],
      "options": {
        "unique_key": "author_id",
        "duplicate_strategy": "update"
      }
    },
    {
      "_comment": "Step 3: HasMany - Books vengono creati dopo Author, da JSON",
      "model": "App\\Models\\Book",
      "execution_order": 3,
      "columns": [
        { 
          "source": "books", 
          "target": "books.*.title",
          "transforms": ["json_decode"]
        },
        { 
          "source": "books", 
          "target": "books.*.isbn",
          "transforms": ["json_decode"]
        }
      ],
      "options": {
        "unique_key": "isbn",
        "duplicate_strategy": "update"
      }
    },
    {
      "_comment": "Step 4: BelongsToMany - Tags vengono creati e collegati dopo Book",
      "model": "App\\Models\\Tag",
      "execution_order": 4,
      "columns": [
        { 
          "source": "books", 
          "target": "books.*.tags.*.name",
          "transforms": ["json_decode"]
        }
      ],
      "options": {
        "unique_key": "slug",
        "duplicate_strategy": "update"
      }
    }
  ]
}
```

**Ordine di esecuzione**:
1. Author (campi diretti: name, email, country)
2. Profile (HasOne: profile.bio, profile.website → author_id automatico)
3. Book (HasMany: books.*.title → author_id automatico)
4. Tag (BelongsToMany: books.*.tags.*.name → collegato via pivot)

### Mapping con BelongsTo

**Scenario**: Importare Book che richiede Author esistente.

```json
{
  "mappings": [
    {
      "model": "App\\Models\\Author",
      "execution_order": 1,
      "columns": [
        { "source": "author_name", "target": "name" },
        { "source": "author_email", "target": "email" }
      ]
    },
    {
      "_comment": "Book richiede Author esistente - execution_order maggiore",
      "model": "App\\Models\\Book",
      "execution_order": 2,
      "columns": [
        { 
          "_comment": "BelongsTo: lookup Author per email, risolve author_id automaticamente",
          "source": "author_email", 
          "target": "author.email" 
        },
        { "source": "title", "target": "title" },
        { "source": "isbn", "target": "isbn" }
      ]
    }
  ]
}
```

## Note

- I campi opzionali possono essere omessi se non necessari
- Il `source_schema` è utile per validazione ma non obbligatorio
- Il `flow_config` può essere gestito anche dal file `config/inflow.php`
- I `transforms` vengono applicati nell'ordine specificato
- Le relazioni devono essere mappate nell'ordine corretto (genitore prima dei figli)

## Determinare il Modello Root

Il sistema analizza automaticamente le dipendenze (BelongsTo) per suggerire il modello root.

**Algoritmo**:
1. Analizza tutti i modelli nel namespace `App\Models`
2. Conta le dipendenze BelongsTo per ogni modello
3. Suggerisce modelli "root" (senza BelongsTo o con BelongsTo nullable)
4. Avvisa se il modello specificato ha dipendenze obbligatorie

**Esempio**:
```bash
# Modello non specificato → sistema suggerisce
sail artisan inflow:process books_100_nested.csv

# Output:
# ✅ Root models found (no BelongsTo dependencies):
#    1. App\Models\Author (HasOne: profile, HasMany: books)
#    2. App\Models\Tag (BelongsToMany: books)
# 
# ⚠️  Models with dependencies:
#    - App\Models\Book (requires: author)
#    - App\Models\Profile (requires: author)
```

**Quando specifici un modello con dipendenze**:
```bash
sail artisan inflow:process books_100_nested.csv "App\Models\Book"

# Output:
# ⚠️  Model App\Models\Book has dependencies:
#    - BelongsTo: author (required)
# 
# 💡 This model requires 'author' to exist first.
#    Ensure Author is mapped with execution_order: 1
#    and Book has execution_order: 2 or greater.
# 
# Continue? [y/N]
```

## Best Practices

### ✅ DO (Fai)

1. **Definisci cast nel modello** per conversioni di tipo automatiche
2. **Usa mutator nel modello** per normalizzazione (trim, lower, slug, ecc.)
3. **Definisci default nella migration** per valori di default del database
4. **Definisci rules() nel modello** per regole di validazione
5. **Mantieni il mapping minimale** - solo pulizia base (`trim`) o trasformazioni ETL-specifiche
6. **Usa default nel mapping solo come fallback** se non definito nella migration
7. **Usa validation_rule nel mapping solo come fallback** se non definito nel modello
8. **Rileva automaticamente** cast/mutator/default/rules durante la creazione del mapping
9. **Rimuovi trasformazioni/default/validation ridondanti** quando suggerito dal sistema
10. **Usa modelli root** (senza BelongsTo) come punto di partenza quando possibile
11. **Rispetta execution_order** - modelli con BelongsTo devono avere execution_order maggiore
12. **Mappa prima campi diretti** - poi aggiungi relazioni in ordine di dipendenza

### ❌ DON'T (Non fare)

1. **Non duplicare logica** - se il modello ha cast/mutator/rules, non ripeterla nel mapping
2. **Non usare `cast:*` nel mapping** se il modello ha già il cast corrispondente
3. **Non usare `trim`, `lower`, ecc.** se il modello ha già un mutator che lo fa
4. **Non duplicare default** - se la migration ha già `->default(value)`, non ripeterlo nel mapping
5. **Non duplicare validation_rule** - se il modello ha già `rules()['field']`, non ripeterlo nel mapping
6. **Non ignorare execution_order** - modelli con BelongsTo devono essere eseguiti dopo il modello a cui appartengono
7. **Non mappare relazioni prima dei campi diretti** - completa prima il modello root
8. **Non ignorare i suggerimenti** del sistema quando rileva ridondanze o dipendenze

### Esempi di Ridondanze da Evitare

```json
// ❌ RIDONDANTE: Modello ha già 'price' => 'decimal:2'
{
  "source": "price",
  "target": "price",
  "transforms": ["cast:decimal:2"]
}

// ✅ CORRETTO: Lascia che il modello gestisca il cast
{
  "source": "price",
  "target": "price"
}

// ❌ RIDONDANTE: Modello ha mutator setEmailAttribute() che fa trim/lower
{
  "source": "email",
  "target": "email",
  "transforms": ["trim", "lower"]
}

// ✅ CORRETTO: Solo trim se necessario, il mutator gestisce lower
{
  "source": "email",
  "target": "email",
  "transforms": ["trim"]
}

// ❌ RIDONDANTE: Migration ha già ->default(0)
{
  "source": "price",
  "target": "price",
  "default": 0.0
}

// ✅ CORRETTO: Lascia che la migration gestisca il default
{
  "source": "price",
  "target": "price"
}

// ❌ RIDONDANTE: Migration ha già ->default(true)
{
  "source": "active",
  "target": "is_active",
  "default": true
}

// ✅ CORRETTO: Lascia che la migration gestisca il default
{
  "source": "active",
  "target": "is_active"
}

// ✅ VALIDO: Default nel mapping come fallback (migration non ha default)
{
  "source": "status",
  "target": "status",
  "default": "pending"
  // Usa solo se la migration NON ha ->default('pending')
}

// ❌ RIDONDANTE: Modello ha già rules()['name']
{
  "source": "name",
  "target": "name",
  "validation_rule": "required|string|min:2|max:100"
}

// ✅ CORRETTO: Lascia che il modello gestisca la validazione
{
  "source": "name",
  "target": "name"
}

// ❌ RIDONDANTE: Modello ha già rules()['price']
{
  "source": "price",
  "target": "price",
  "validation_rule": "required|numeric|min:0|max:9999.99"
}

// ✅ CORRETTO: Lascia che il modello gestisca la validazione
{
  "source": "price",
  "target": "price"
}

// ✅ VALIDO: Validation_rule nel mapping come fallback (modello non ha rules())
{
  "source": "custom_field",
  "target": "custom_field",
  "validation_rule": "required|string|max:255"
  // Usa solo se il modello NON ha rules()['custom_field']
}
```

