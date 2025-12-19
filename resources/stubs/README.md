# InFlow ETL - Guida ai File di Test

Questa guida documenta tutti i file di test disponibili per testare le funzionalità del package InFlow ETL.

## Test Suite Ufficiale

Questa è la **test suite ufficiale** di InFlow. Contiene 27 test completi organizzati per categoria, ciascuno con il proprio file di mapping JSON corrispondente.

### Scopo dei File

Questi file hanno un **duplice scopo**:

1. **Test funzionali**: verificare il corretto funzionamento di InFlow con diversi scenari (relazioni, errori, trasformazioni, edge cases)

2. **Driver per lo sviluppo**: servono come riferimento per la costruzione di:
   - **Modelli Eloquent**: struttura dei campi, fillable, casts
   - **Relazioni**: BelongsTo, HasOne, HasMany, BelongsToMany con pivot
   - **Migrazioni**: tipi di colonna, nullable, unique, foreign keys
   - **Regole di validazione**: rules() method nei modelli
   - **Mapping JSON**: struttura del file di mapping con transforms e options

Ogni file di test è progettato per esercitare specifiche funzionalità e può essere usato come template per capire come strutturare i propri dati e modelli.

### Esecuzione Automatica

Per eseguire tutti i test ufficiali:

```bash
./run_all_tests_complete.sh
```

Lo script esegue automaticamente tutti i 27 test con i mapping corrispondenti e genera report dettagliati.

## Mapping Files

Ogni stub di test ha un corrispondente file di mapping JSON nella directory `mappings/` con la stessa struttura organizzativa. I mapping sono **necessari** per eseguire i test con `--no-interaction` e garantiscono che:

- Le relazioni siano configurate correttamente (lookup, create_if_missing)
- Le validation rules siano complete
- I transform siano applicati dove necessario
- Le opzioni (duplicate_strategy, unique_key) siano configurate

**Convenzione di nomenclatura**: `{stub_name}_{ModelClass}.json`

**Esempio di uso**:
```bash
# Con mapping (raccomandato)
vendor/bin/sail artisan inflow:process \
  packages/inflow/resources/stubs/relations/belongs_to.csv \
  "App\\Models\\Book" \
  --mapping=packages/inflow/resources/stubs/mappings/relations/belongs_to_App_Models_Book.json \
  --no-interaction

# Senza mapping (auto-generato, può essere incompleto)
vendor/bin/sail artisan inflow:process \
  packages/inflow/resources/stubs/relations/belongs_to.csv \
  "App\\Models\\Book" \
  --no-interaction
```

Vedi `mappings/README.md` per la lista completa dei mapping disponibili.

## Posizione dei File

I file di test si trovano in: `packages/inflow/resources/stubs/`

```
resources/stubs/
├── README.md           # Questo file
├── mappings/           # Mapping JSON per gli stub
│   ├── README.md       # Documentazione mapping
│   ├── relations/      # Mapping per test relazioni
│   ├── field_types/    # Mapping per test tipi campo
│   ├── errors/         # Mapping per test errori
│   ├── transforms/     # Mapping per test trasformazioni
│   ├── edge_cases/     # Mapping per test casi limite
│   └── validation/     # Mapping per test validazione
├── relations/          # Test relazioni Eloquent
├── field_types/        # Test tipi di campo
├── errors/             # Test scenari di errore
├── transforms/         # Test trasformazioni
├── edge_cases/         # Test casi limite
└── validation/         # Test regole di validazione
```

### Mapping Files

Ogni stub di test ha un corrispondente file di mapping JSON nella directory `mappings/` con la stessa struttura. I mapping sono necessari per eseguire i test con `--no-interaction` e garantiscono che le relazioni, le validazioni e i transform siano configurati correttamente.

Vedi `mappings/README.md` per la lista completa dei mapping disponibili.

## Schema Database

```
┌─────────────────────────────────────────────────────────────┐
│                    SCHEMA RELAZIONI                         │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Author ──HasMany──> Book                                   │
│     │                  │                                    │
│     └──HasOne──> Profile                                    │
│                        │                                    │
│  Book ──BelongsTo──> Author                                 │
│     │                                                       │
│     └──BelongsToMany──> Tag (pivot: book_tag)              │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Modelli Disponibili

| Model | Tabella | Relazioni |
|-------|---------|-----------|
| `Author` | `authors` | hasMany(Book), hasOne(Profile) |
| `Profile` | `profiles` | belongsTo(Author) |
| `Book` | `books` | belongsTo(Author), belongsToMany(Tag) |
| `Tag` | `tags` | belongsToMany(Book) |

---

## 1. Test Relazioni

### `relations/belongs_to.csv`

**Scopo**: Testare la relazione BelongsTo (Book → Author via lookup)

**Righe**: 10

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/relations/belongs_to.csv "App\\Models\\Book" --mapping=packages/inflow/resources/stubs/mappings/relations/belongs_to_App_Models_Book.json --no-interaction
```

**Cosa testa**:
- Lookup dell'autore tramite nome
- Creazione FK `author_id` automatica
- Gestione di autori esistenti vs nuovi

**Risultato atteso**: 10 libri importati, autori creati se non esistenti

---

### `relations/has_one.json`

**Scopo**: Testare la relazione HasOne (Author → Profile)

**Righe**: 10

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/relations/has_one.json "App\\Models\\Author" --mapping=packages/inflow/resources/stubs/mappings/relations/has_one_App_Models_Author.json --no-interaction
```

**Cosa testa**:
- Creazione automatica del profilo nested
- Campi nullable nel profilo
- Mapping di oggetti nested

**Risultato atteso**: 10 autori con relativi profili creati

---

### `relations/has_many.json`

**Scopo**: Testare la relazione HasMany (Author con array di Books)

**Righe**: 5 autori con 2-3 libri ciascuno

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/relations/has_many.json "App\\Models\\Author" --mapping=packages/inflow/resources/stubs/mappings/relations/has_many_App_Models_Author.json --no-interaction
```

**Cosa testa**:
- Parsing di array nested
- Creazione batch di record correlati
- FK automatiche sui figli

**Risultato atteso**: 5 autori con ~13 libri totali

---

### `relations/belongs_to_many.csv`

**Scopo**: Testare la relazione BelongsToMany (Book ↔ Tag con pivot)

**Righe**: 10

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/relations/belongs_to_many.csv "App\\Models\\Book" --mapping=packages/inflow/resources/stubs/mappings/relations/belongs_to_many_App_Models_Book.json --no-interaction
```

**Cosa testa**:
- Parsing di tag separati da virgola
- Creazione/lookup tag
- Popolamento pivot table con `order`

**Risultato atteso**: 10 libri con tag associati via pivot

---

## 2. Test Tipi Campo

### `field_types/all_types.csv`

**Scopo**: Verificare il parsing corretto di tutti i tipi di dato

**Righe**: 10

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/field_types/all_types.csv "App\\Models\\Author" --no-interaction
```

**Cosa testa**:
- `string`: name, email, country
- `decimal`: price
- `date`: published_at
- `boolean`: is_active

**Risultato atteso**: 10 record con tipi corretti

---

### `field_types/nullable_fields.json`

**Scopo**: Verificare la gestione di campi nullable

**Righe**: 10

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/field_types/nullable_fields.json "App\\Models\\Author" --no-interaction
```

**Cosa testa**:
- Campi `null` espliciti
- Campi assenti vs vuoti
- Relazioni nullable (profile può essere null)
- Stringhe vuote vs null

**Risultato atteso**: 10 record con valori null/empty gestiti correttamente

---

## 3. Test Errori

### `errors/type_mismatch.csv`

**Scopo**: Verificare la gestione di errori di tipo

**Righe**: 10 (alcune con errori intenzionali)

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/errors/type_mismatch.csv "App\\Models\\Book" --no-interaction
```

**Cosa testa**:
- Stringa dove atteso numero
- Data invalida
- Boolean non riconosciuto
- Prezzo negativo
- ISBN troppo lungo

**Risultato atteso**: Partial success, alcuni record falliti con errori chiari

---

### `errors/missing_required.json`

**Scopo**: Verificare la validazione dei campi required

**Righe**: 10 (alcune mancanti di campi required)

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/errors/missing_required.json "App\\Models\\Author" --no-interaction
```

**Cosa testa**:
- Campo `name` null
- Campo `email` assente
- Stringa vuota vs null

**Risultato atteso**: Errori di validazione per record incompleti

---

### `errors/invalid_fk.csv`

**Scopo**: Verificare la gestione di FK non valide

**Righe**: 10 (alcune con autori inesistenti)

**Comando**:
```bash
# Prima creare un autore di test:
vendor/bin/sail artisan tinker --execute="App\Models\Author::create(['name'=>'Test','email'=>'existing@authors.com','country'=>'USA'])"

# Poi importare:
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/errors/invalid_fk.csv "App\\Models\\Book" --no-interaction
```

**Cosa testa**:
- FK verso record inesistente
- Email vuota come FK
- Case sensitivity nel lookup
- Spazi extra nel valore FK

**Risultato atteso**: Errori FK per lookup falliti

---

### `errors/duplicates.csv`

**Scopo**: Verificare la gestione di record duplicati

**Righe**: 10 (con ISBN duplicati)

**Prerequisiti**: Creare prima gli autori referenziati (o usare un mapping senza FK required)

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/errors/duplicates.csv "App\\Models\\Book" --no-interaction
```

**Cosa testa**:
- Unique constraint su ISBN
- Strategia skip/update/error
- Conteggio duplicati
- Errori FK quando autore non esiste

**Risultato atteso**: Dipende dalla strategia configurata e dalla presenza degli autori

---

### `errors/relation_missing_required.csv`

**Scopo**: Testare l'errore quando si crea una relazione ma mancano campi required

**Righe**: 1

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/errors/relation_missing_required.csv "App\\Models\\Book"
```

**Cosa testa**:
- Creazione Author con `create_if_missing=true`
- Manca `email` (required nel modello Author se non nullable)
- Messaggio errore dettagliato con suggerimenti

**Risultato atteso**: Errore "Cannot create/lookup related 'author' (missing_required)" con hint specifico

---

### `errors/unique_violation.csv`

**Scopo**: Testare la violazione di unique constraint

**Righe**: 2 (stessa ISBN)

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/errors/unique_violation.csv "App\\Models\\Book"
```

**Configurazione richiesta**:
- Unique field: `isbn`
- **Duplicate strategy: "Error"** ← importante!

> ⚠️ Se selezioni "Update" o "Skip", non vedrai l'errore perché il sistema gestirà il duplicato secondo la strategia scelta.

**Cosa testa**:
- Prima riga: inserimento OK
- Seconda riga: viola unique su ISBN
- Gestione interattiva dell'errore

**Risultato atteso**: Errore "Duplicate key / unique constraint violation"

---

### `errors/string_too_long.csv`

**Scopo**: Testare valori troppo lunghi per le colonne

**Righe**: 2 (uno normale, uno con titolo > 255 caratteri)

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/errors/string_too_long.csv "App\\Models\\Book"
```

**Cosa testa**:
- Valore che supera lunghezza massima colonna VARCHAR(255)
- Troncamento automatico con warning

**Risultato atteso**: 
- 2 righe importate con successo
- Warning: `1 field(s) were truncated... Row 2, field 'title' (271 → 255 chars)`

> ℹ️ InFlow tronca automaticamente i valori troppo lunghi invece di fallire, mostrando un warning dettagliato.

---

### `errors/duplicate_author.csv`

**Scopo**: Testare duplicati nella creazione di relazioni BelongsTo

**Righe**: 2 (stesso autore per due libri)

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/errors/duplicate_author.csv "App\\Models\\Book"
```

**Cosa testa**:
- `firstOrCreate` per gestire duplicati Author
- Mappatura con `author_email` aggiuntivo
- Lookup per email unica

**Risultato atteso**: Primo libro crea Author, secondo libro riutilizza lo stesso

---

## 4. Test Transform

### `transforms/date_formats.csv`

**Scopo**: Testare il parsing di vari formati data

**Righe**: 10

**Colonne disponibili**:
| Colonna | Formato | Esempio | Transform richiesto |
|---------|---------|---------|---------------------|
| `date_us` | MM/DD/YYYY | 05/15/2023 | `parse_date:m/d/Y` |
| `date_eu` | DD/MM/YYYY | 15/05/2023 | `parse_date:d/m/Y` |
| `date_iso` | YYYY-MM-DD | 2023-05-15 | nessuno (auto) |
| `date_full` | Month DD YYYY | May 15 2023 | `parse_date:F d Y` |

**Comando** (usando `date_iso` che non richiede transform):
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/transforms/date_formats.csv "App\\Models\\Book"
```

**Configurazione consigliata**:
- Mappa `date_iso` → `published_at` (formato ISO, parsing automatico)
- Oppure mappa `date_eu` → `published_at` e applica transform `parse_date:d/m/Y`

> ⚠️ Se mappi `date_eu` senza transform, otterrai errori "Failed to parse time string" perché PHP interpreta DD/MM come MM/DD.

**Cosa testa**:
- Parsing automatico formato ISO
- Transform `parse_date` per formati custom (EU, US, esteso)
- Gestione errori per formati non riconosciuti

**Risultato atteso**: Date parsate correttamente con i transform appropriati

---

### `transforms/string_transforms.json`

**Scopo**: Testare le trasformazioni stringa

**Righe**: 10

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/transforms/string_transforms.json "App\\Models\\Author" --no-interaction
```

**Cosa testa**:
- `trim`: rimuove spazi
- `lowercase`/`uppercase`
- `slug`: genera slug da testo
- Tab e newline nel testo

**Risultato atteso**: Stringhe trasformate secondo configurazione

---

### `transforms/complex_mapping.xml`

**Scopo**: Testare mapping complessi con XML nested

**Righe**: 3 autori con libri nested

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/transforms/complex_mapping.xml "App\\Models\\Author" --no-interaction
```

**Cosa testa**:
- Parsing XML
- Mapping da path nested (es. `personal_info.full_name` → `name`)
- Array di pubblicazioni nested
- Combinazione HasOne (biography) + HasMany (publications)

**Risultato atteso**: 3 autori con profili e libri

---

### `transforms/numeric_transforms.csv`

**Scopo**: Testare tutte le trasformazioni numeriche

**Righe**: 10

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/transforms/numeric_transforms.csv "App\\Models\\Book"
```

**Cosa testa**:
- `round`: arrotondamento (19.999 → 20)
- `floor`: arrotondamento verso il basso (15.123 → 15)
- `ceil`: arrotondamento verso l'alto (12.001 → 13)
- `multiply`: moltiplicazione (per calcolo sconti)
- `divide`: divisione (per normalizzazione)
- `to_cents`: conversione a centesimi (25.99 → 2599)
- `from_cents`: conversione da centesimi (2599 → 25.99)
- Valori negativi, zero, e grandi numeri

**Risultato atteso**: 10 record con valori numerici trasformati

---

### `transforms/utility_transforms.json`

**Scopo**: Testare trasformazioni utility (coalesce, null_if_empty, json, split)

**Righe**: 8

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/transforms/utility_transforms.json "App\\Models\\Author"
```

**Cosa testa**:
- `coalesce`: fallback tra campi (email vuota → backup_email)
- `null_if_empty`: stringa vuota → null
- `json_decode`: parsing JSON embedded
- `split`: parsing di stringhe delimitate (tag1,tag2,tag3)
- JSON array, oggetti nested, JSON invalido
- Campi con solo whitespace
- Unicode nel JSON

**Risultato atteso**: 8 record con valori utility-transformati

---

### `transforms/case_transforms.csv`

**Scopo**: Testare trasformazioni di case e formattazione testo

**Righe**: 10

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/transforms/case_transforms.csv "App\\Models\\Book"
```

**Cosa testa**:
- `camelCase`: "hello world" → "helloWorld"
- `snake_case`: "HelloWorld" → "hello_world"
- `title`: "the quick brown fox" → "The Quick Brown Fox"
- `prefix`: aggiunge prefisso
- `suffix`: aggiunge suffisso
- `truncate`: tronca a N caratteri
- `strip_tags`: rimuove HTML/script tags
- Valori vuoti e già formattati
- Unicode (café, résumé)

**Risultato atteso**: 10 record con case/formattazione applicati

---

## 5. Test Edge Cases

### `edge_cases/empty_values.csv`

**Scopo**: Testare la distinzione tra empty string e null

**Righe**: 10

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/edge_cases/empty_values.csv "App\\Models\\Author" --no-interaction
```

**Cosa testa**:
- Campo vuoto vs campo con spazi
- Valori quoted vuoti
- Campi con solo virgole

**Risultato atteso**: Gestione corretta di empty vs null

---

### `edge_cases/special_chars.json`

**Scopo**: Testare caratteri speciali e unicode

**Righe**: 10

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/edge_cases/special_chars.json "App\\Models\\Author" --no-interaction
```

**Cosa testa**:
- Caratteri accentati (áéíóú, äöü, ç)
- Caratteri asiatici (日本語)
- Emoji (🎉📚)
- Quote e backslash
- Tag HTML/script (XSS)
- Null bytes

**Risultato atteso**: Caratteri preservati (tranne sanitization di controlli)

---

### `edge_cases/large_text.csv`

**Scopo**: Testare campi text molto lunghi

**Righe**: 10

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/edge_cases/large_text.csv "App\\Models\\Author" --no-interaction
```

**Cosa testa**:
- Bio corta vs molto lunga
- Contenuto con numeri e formati misti
- JSON-like, URL, code snippets nel testo

**Risultato atteso**: Testo preservato senza troncamento

---

## 6. Test Validation Rules

I model hanno validation rules definite tramite metodo statico `rules()`:

### Rules Implementate

| Model | Campo | Regole |
|-------|-------|--------|
| **Author** | name | required, string, min:2, max:100 |
| | email | required, email, unique |
| | country | nullable, string, size:2, alpha |
| **Book** | author_id | required, exists:authors |
| | title | required, string, min:1, max:255 |
| | isbn | required, regex (978-XXXXXXXXXX), unique |
| | price | required, numeric, min:0, max:9999.99 |
| | published_at | nullable, date, before_or_equal:today |
| **Profile** | author_id | required, exists:authors |
| | bio | nullable, string, max:1000 |
| | website | nullable, url, max:255 |
| **Tag** | name | required, string, min:2, max:50 |
| | slug | required, alpha_dash, max:50, unique |

---

### `validation/author_rules.csv`

**Scopo**: Testare tutte le validation rules di Author

**Righe**: 11 (mix di validi e invalidi)

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/validation/author_rules.csv "App\\Models\\Author" --no-interaction
```

**Cosa testa**:
- `name` troppo corto (min:2)
- `name` troppo lungo (max:100)
- `email` mancante (required)
- `email` formato invalido
- `email` duplicato (unique)
- `country` troppo lungo (size:2)
- `country` con numeri (alpha)

**Risultato atteso**: ~4 record validi, ~7 errori di validazione

---

### `validation/book_rules.json`

**Scopo**: Testare tutte le validation rules di Book

**Righe**: 10 (mix di validi e invalidi)

**Prerequisiti**: Creare prima un autore con email `author1@test.com`

**Comando**:
```bash
vendor/bin/sail artisan tinker --execute="App\Models\Author::create(['name'=>'Test Author','email'=>'author1@test.com'])"
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/validation/book_rules.json "App\\Models\\Book" --no-interaction
```

**Cosa testa**:
- `title` vuoto (required)
- `isbn` formato invalido (regex)
- `isbn` troppo corto
- `isbn` duplicato (unique)
- `price` negativo (min:0)
- `price` troppo alto (max:9999.99)
- `published_at` nel futuro (before_or_equal:today)
- `author_id` inesistente (exists)

**Risultato atteso**: ~2 record validi, ~8 errori di validazione

---

### `validation/profile_rules.json`

**Scopo**: Testare tutte le validation rules di Profile

**Righe**: 8 (mix di validi e invalidi)

**Prerequisiti**: Creare autori con email profile1@test.com ... profile7@test.com

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/validation/profile_rules.json "App\\Models\\Profile" --no-interaction
```

**Cosa testa**:
- `bio` troppo lungo (max:1000)
- `website` formato invalido (url)
- `website` protocollo sbagliato (ftp://)
- `author_id` inesistente (exists)
- `verified_at` formato data invalido

**Risultato atteso**: ~3 record validi, ~5 errori di validazione

---

### `validation/tag_rules.csv`

**Scopo**: Testare tutte le validation rules di Tag

**Righe**: 11 (mix di validi e invalidi)

**Comando**:
```bash
vendor/bin/sail artisan inflow:process packages/inflow/resources/stubs/validation/tag_rules.csv "App\\Models\\Tag" --no-interaction
```

**Cosa testa**:
- `name` troppo corto (min:2)
- `name` troppo lungo (max:50)
- `slug` con spazi (alpha_dash)
- `slug` con caratteri speciali
- `slug` duplicato (unique)

**Risultato atteso**: ~5 record validi, ~6 errori di validazione

---

## Riepilogo File

| Categoria | File | Formato | Righe | Model Target |
|-----------|------|---------|-------|--------------|
| Relations | belongs_to.csv | CSV | 10 | Book |
| Relations | has_one.json | JSON | 10 | Author |
| Relations | has_many.json | JSON | 5 | Author |
| Relations | belongs_to_many.csv | CSV | 10 | Book |
| Field Types | all_types.csv | CSV | 10 | Author |
| Field Types | nullable_fields.json | JSON | 10 | Author |
| Errors | type_mismatch.csv | CSV | 10 | Book |
| Errors | missing_required.json | JSON | 10 | Author |
| Errors | invalid_fk.csv | CSV | 10 | Book |
| Errors | duplicates.csv | CSV | 10 | Book |
| Transforms | date_formats.csv | CSV | 10 | Book |
| Transforms | string_transforms.json | JSON | 10 | Author |
| Transforms | numeric_transforms.csv | CSV | 10 | Book |
| Transforms | utility_transforms.json | JSON | 8 | Author |
| Transforms | case_transforms.csv | CSV | 10 | Book |
| Transforms | complex_mapping.xml | XML | 3 | Author |
| Edge Cases | empty_values.csv | CSV | 10 | Author |
| Edge Cases | special_chars.json | JSON | 10 | Author |
| Edge Cases | large_text.csv | CSV | 10 | Author |
| **Validation** | author_rules.csv | CSV | 11 | Author |
| **Validation** | book_rules.json | JSON | 10 | Book |
| **Validation** | profile_rules.json | JSON | 8 | Profile |
| **Validation** | tag_rules.csv | CSV | 11 | Tag |

**Totale**: 23 file di test

---

## Setup Iniziale

Prima di eseguire i test, assicurarsi di:

1. **Eseguire le migration**:
```bash
vendor/bin/sail artisan migrate:fresh
```

2. **Verificare i modelli**:
```bash
vendor/bin/sail artisan tinker --execute="echo class_exists('App\Models\Author')"
```

3. **Pulire i mapping esistenti** (opzionale):
```bash
rm -rf mappings/*.json
```

