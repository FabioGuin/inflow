# Mapping Template - Guida Completa

Questo file (`mapping-template.json`) è un template completo che mostra tutte le opzioni disponibili per creare un mapping InFlow ETL.

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

### Column Mapping

Ogni colonna può avere:

- **`source`** (obbligatorio): Nome della colonna sorgente
- **`target`** (obbligatorio): Path di destinazione (es. "name", "author.email", "books.*.title")
- **`transforms`** (opzionale): Array di trasformazioni da applicare
- **`default`** (opzionale): Valore di default se la colonna è vuota
- **`validation_rule`** (opzionale): Regola di validazione Laravel
- **`relation_lookup`** (opzionale): Configurazione per lookup di relazioni

#### Transforms Disponibili

- **String**: `trim`, `lower`, `upper`, `title`, `slug`, `truncate:N`
- **Numeric**: `cast:int`, `cast:float`, `cast:decimal:N`
- **Decimal**: `cast:decimal:2` (per valori monetari con precisione)
- **Date**: `cast:date`, `date_format:Y-m-d`
- **Timestamp**: `cast:datetime`, `date_format:Y-m-d H:i:s`
- **Time**: `cast:time`, `date_format:H:i:s`
- **Boolean**: `cast:bool`
- **JSON**: `json_decode` (decodifica stringa JSON in array/oggetto)
- **Array/String**: `split:delimiter`
- **Custom**: Trasformazioni personalizzate registrate in config

**Note sui tipi**:
- **JSON**: Le colonne di tipo `json` contengono stringhe JSON valide (array o oggetti). Usa `json_decode` per convertirle in array/oggetti PHP prima di mapparle a relazioni.
- **Decimal**: Distinto da `float`, viene rilevato quando i valori hanno tipicamente 2-4 decimali (valori monetari). Usa `cast:decimal:2` per precisione.
- **Timestamp**: Include sia data che ora. Distinto da `date` che contiene solo la data.
- **Time**: Contiene solo l'ora, senza data.

#### Relation Lookup

Per relazioni (belongsTo, hasOne, hasMany, belongsToMany):

```json
{
  "relation_lookup": {
    "field": "email|name|id",
    "create_if_missing": true|false
  }
}
```

#### Target Paths

- **Campo diretto**: `"name"` → `$model->name`
- **Relazione singola**: `"author.email"` → `$model->author->email`
- **Relazione multipla**: `"books.*.title"` → `$model->books[0]->title`
- **Relazione annidata**: `"books.*.tags.*.name"` → `$model->books[0]->tags[0]->name`

### Options

- **`unique_key`**: Campo o array di campi per identificare record duplicati
- **`duplicate_strategy`**: 
  - `"update"`: Aggiorna il record esistente
  - `"skip"`: Salta il record duplicato
  - `"error"`: Genera un errore

## Esempi di Uso

### Mapping Semplice

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
          "source": "email",
          "target": "email",
          "transforms": ["trim", "lower"]
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

### Mapping con Relazioni

```json
{
  "name": "Author with Books",
  "mappings": [
    {
      "model": "App\\Models\\Author",
      "execution_order": 1,
      "columns": [
        {
          "source": "name",
          "target": "name"
        },
        {
          "source": "books.*.title",
          "target": "books.*.title"
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

### Mapping con Lookup

```json
{
  "source": "author_email",
  "target": "author.email",
  "relation_lookup": {
    "field": "email",
    "create_if_missing": false
  }
}
```

## Note

- I campi opzionali possono essere omessi se non necessari
- Il `source_schema` è utile per validazione ma non obbligatorio
- Il `flow_config` può essere gestito anche dal file `config/inflow.php`
- I `transforms` vengono applicati nell'ordine specificato
- Le relazioni devono essere mappate nell'ordine corretto (genitore prima dei figli)

