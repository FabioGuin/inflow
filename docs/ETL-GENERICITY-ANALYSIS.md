# Analisi Genericità ETL

## ✅ Aspetti Generici (Agnostici)

### 1. **Nessun Hardcoding di Modelli**
- ✅ Nessun nome di modello hardcoded nel codice (solo esempi nei commenti)
- ✅ Tutti i modelli sono passati come stringhe di classi (`$modelClass`)
- ✅ Le relazioni sono rilevate dinamicamente usando reflection

### 2. **Rilevamento Dinamico delle Relazioni**
- ✅ `ModelDependencyService` analizza le dipendenze usando reflection
- ✅ `RelationTypeService` determina il tipo di relazione dinamicamente
- ✅ `injectForeignKeysFromCreatedModels` usa `getForeignKeyName()` per trovare foreign keys
- ✅ `processNestedMappings` verifica relazioni con `method_exists()` e reflection

### 3. **Parsing Generico dei Target**
- ✅ `parseTarget()` estrae attributi/relazioni da stringhe come `"name"`, `"profile.bio"`, `"books.*.title"`
- ✅ Usa regex generiche per identificare pattern (`/^([^.*]+)\.\*\./`)
- ✅ Non assume nomi specifici di colonne o relazioni

### 4. **Gestione Ordine di Esecuzione**
- ✅ `ExecutionOrderService` calcola l'ordine usando topological sort
- ✅ Basato su analisi delle dipendenze `BelongsTo`, non su nomi hardcoded
- ✅ Funziona per qualsiasi gerarchia di modelli

### 5. **Mapping Configuration-Driven**
- ✅ Tutto è guidato dal JSON mapping, non da logica hardcoded
- ✅ `execution_order`, `unique_key`, `duplicate_strategy` sono configurabili
- ✅ Supporta qualsiasi struttura di dati definita nel mapping

---

## ⚠️ Assunzioni e Limitazioni

### 1. **Struttura Array Mappings**
**Assunzione**: Un array mapping deve avere tutte le colonne con la stessa `source`.

```php
// ✅ OK: tutte le colonne hanno source="books"
[
  {source: "books", target: "books.*.title"},
  {source: "books", target: "books.*.isbn"}
]

// ❌ NON SUPPORTATO: colonne con source diverse
[
  {source: "books", target: "books.*.title"},
  {source: "authors", target: "books.*.author_id"}  // ❌
]
```

**Impatto**: Limita la flessibilità per array complessi con dati da più colonne.

---

### 2. **Pattern Nested Mappings**
**Assunzione**: I nested mappings devono avere target con pattern `relation.*.attribute`.

```php
// ✅ OK: pattern riconosciuto
{source: "books", target: "tags.*.name"}

// ❌ NON RICONOSCIUTO: pattern diverso
{source: "books", target: "tags[].name"}  // ❌
{source: "books", target: "tags/name"}   // ❌
```

**Impatto**: Solo pattern `relation.*.attribute` è supportato per nested relations.

---

### 3. **Ordine di Iniezione Foreign Keys**
**Assunzione**: I foreign keys vengono iniettati nell'ordine corretto basato su `execution_order`.

```php
// ✅ OK: Author (order 1) → Book (order 2) → Tag (order 3)
// author_id viene iniettato in Book, book_id in Tag

// ⚠️ PROBLEMA: Se execution_order è sbagliato, foreign keys mancano
```

**Impatto**: Richiede che `execution_order` sia calcolato correttamente. Se sbagliato, foreign keys mancano.

---

### 4. **Relazioni Definite nei Modelli**
**Assunzione**: Le relazioni devono essere definite correttamente nei modelli Eloquent.

```php
// ✅ OK: relazione definita
class Book extends Model {
    public function author() { return $this->belongsTo(Author::class); }
}

// ❌ PROBLEMA: se relazione non esiste o nome sbagliato
// processNestedMappings() salta silenziosamente
```

**Impatto**: Se una relazione non esiste o ha un nome diverso, il nested mapping viene ignorato senza errore.

---

### 5. **JSON Array Structure**
**Assunzione**: Gli array JSON devono essere decodificabili e avere struttura coerente.

```php
// ✅ OK: array di oggetti
[{"title": "...", "isbn": "..."}, {...}]

// ❌ PROBLEMA: struttura diversa
{"book1": {...}, "book2": {...}}  // oggetto invece di array
```

**Impatto**: Solo array numerici sono supportati, non oggetti con chiavi.

---

### 6. **Nested Relations in Sub-Row**
**Assunzione**: I nested data devono essere accessibili nel `subRow` con lo stesso nome della relazione.

```php
// ✅ OK: subRow contiene "tags" array
$subRow->get("tags")  // ritorna array di tag

// ❌ PROBLEMA: se nested data ha nome diverso
$subRow->get("book_tags")  // non viene trovato
```

**Impatto**: Il nome della colonna nel JSON deve matchare il nome della relazione nel modello.

---

## 🔧 Casi Edge Non Coperti

### 1. **Multiple Sources per Array Mapping**
Non supportato: mappare un array da più colonne sorgente.

```json
// ❌ NON SUPPORTATO
{
  "source": ["books", "book_metadata"],
  "target": "books.*.title"
}
```

### 2. **Nested Relations con Nomi Diversi**
Non supportato: relazione nel modello ha nome diverso dalla colonna JSON.

```php
// Modello: public function bookTags() { ... }
// JSON: "tags" array
// ❌ Non matcha automaticamente
```

### 3. **Circular Dependencies**
Rilevato ma non gestito: se ci sono dipendenze circolari, `ExecutionOrderService` le rileva ma non le risolve.

```php
// ⚠️ RILEVATO MA NON RISOLTO
Author → Book → Author (circular)
```

### 4. **Virtual Columns in Array Items**
Non supportato: colonne virtuali (calcolate) dentro array items.

```json
// ❌ NON SUPPORTATO
{
  "books": [{
    "title": "...",
    "_computed": "..."  // colonna virtuale
  }]
}
```

---

## 📊 Valutazione Genericità

| Aspetto | Generico? | Note |
|---------|-----------|------|
| Nomi modelli | ✅ 100% | Nessun hardcoding |
| Nomi relazioni | ✅ 95% | Pattern `relation.*.attribute` richiesto |
| Struttura dati | ✅ 90% | Array numerici supportati, oggetti no |
| Foreign keys | ✅ 95% | Auto-rilevati, richiedono ordine corretto |
| Execution order | ✅ 100% | Calcolato dinamicamente |
| Mapping config | ✅ 100% | Completamente configurabile |
| Edge cases | ⚠️ 70% | Alcuni casi non coperti |

**Punteggio Genericità: ~92%**

---

## 🎯 Raccomandazioni per Maggiore Genericità

### 1. **Supporto Pattern Alternativi**
Aggiungere supporto per pattern diversi:
- `relation[].attribute`
- `relation/attribute`
- Configurabile nel mapping

### 2. **Multiple Sources per Array**
Permettere array mappings da più colonne:
```json
{
  "sources": ["books", "metadata"],
  "target": "books.*.title"
}
```

### 3. **Mapping Nome Relazione → Colonna JSON**
Permettere override del nome:
```json
{
  "source": "tags",
  "target": "bookTags.*.name",  // relazione "bookTags" ma colonna "tags"
  "relation_override": {"bookTags": "tags"}
}
```

### 4. **Gestione Circular Dependencies**
Implementare strategia per dipendenze circolari:
- Warning + suggerimento di `unique_key` per break del ciclo
- O gestione esplicita con `execution_order` manuale

### 5. **Validazione Mapping**
Aggiungere validazione del mapping prima dell'esecuzione:
- Verifica che tutte le relazioni esistano
- Verifica che `execution_order` sia valido
- Warning per potenziali problemi

---

## ✅ Conclusione

L'implementazione è **altamente generica** (~92%) e funziona per qualsiasi struttura di modelli e relazioni Laravel, purché:

1. ✅ Le relazioni siano definite correttamente nei modelli
2. ✅ Il mapping segua i pattern supportati (`relation.*.attribute`)
3. ✅ L'`execution_order` sia calcolato correttamente
4. ✅ I dati JSON siano array numerici (non oggetti)

Le limitazioni principali sono:
- Pattern di target rigidi (solo `relation.*.attribute`)
- Array mappings da singola colonna
- Nome relazione deve matchare colonna JSON

Queste limitazioni sono **ragionevoli** per un ETL e coprono la maggior parte dei casi d'uso reali.

