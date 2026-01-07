# Custom Transforms - Guida all'Estendibilità

Questa guida spiega come creare e registrare transform personalizzati nel sistema InFlow.

## Panoramica

I Transform sono il meccanismo principale per manipolare i valori durante l'import. InFlow fornisce molti transform built-in (trim, upper, cast, parse_date, ecc.) ma permette anche di crearne di personalizzati.

## Tipi di Transform

| Tipo | Esempio | Descrizione |
|------|---------|-------------|
| **Simple** | `trim`, `upper` | Nessun parametro, comportamento fisso |
| **Parameterized** | `cast:int`, `prefix:SKU-` | Accetta parametri via sintassi `name:param` |
| **Interactive** | `parse_date:` | Richiede input utente via CLI |

## Creare un Transform Custom

### 1. Transform Semplice

```php
<?php

namespace App\Transforms;

use InFlow\Contracts\TransformStepInterface;

class CompanyCodeTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Normalizza codice azienda: uppercase, rimuovi spazi, prefisso
        return 'ACME-' . strtoupper(str_replace(' ', '', $value));
    }

    public function getName(): string
    {
        return 'company_code';
    }
}
```

### 2. Transform Parametrizzato

```php
<?php

namespace App\Transforms;

use InFlow\Contracts\TransformStepInterface;

class CurrencyConvertTransform implements TransformStepInterface
{
    public function __construct(
        private string $fromCurrency = 'EUR',
        private string $toCurrency = 'USD',
        private float $rate = 1.0
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        if (!is_numeric($value)) {
            return $value;
        }

        return round((float) $value * $this->rate, 2);
    }

    public function getName(): string
    {
        return "currency_convert:{$this->fromCurrency}:{$this->toCurrency}";
    }

    /**
     * Parse spec like "currency_convert:EUR:USD:1.08"
     */
    public static function fromString(string $spec): self
    {
        $parts = explode(':', $spec);
        
        return new self(
            $parts[1] ?? 'EUR',
            $parts[2] ?? 'USD',
            (float) ($parts[3] ?? 1.0)
        );
    }
}
```

### 3. Transform Interattivo (con prompt CLI)

```php
<?php

namespace App\Transforms;

use InFlow\Contracts\TransformStepInterface;
use InFlow\Transforms\Contracts\InteractiveTransformInterface;

class LookupTransform implements TransformStepInterface, InteractiveTransformInterface
{
    public function __construct(
        private string $table,
        private string $column
    ) {}

    public function transform(mixed $value, array $context = []): mixed
    {
        // Lookup nel database
        return \DB::table($this->table)
            ->where($this->column, $value)
            ->value('id');
    }

    public function getName(): string
    {
        return "lookup:{$this->table}:{$this->column}";
    }

    public static function fromString(string $spec): self
    {
        $parts = explode(':', $spec);
        
        return new self(
            $parts[1] ?? 'items',
            $parts[2] ?? 'code'
        );
    }

    /**
     * Prompt da mostrare nella CLI interattiva
     */
    public static function getPrompts(): array
    {
        return [
            [
                'label' => 'Which table to lookup?',
                'hint' => 'Database table name',
                'examples' => ['products', 'categories', 'suppliers'],
            ],
            [
                'label' => 'Which column to match?',
                'hint' => 'Column containing the lookup value',
                'examples' => ['code', 'sku', 'external_id'],
            ],
        ];
    }

    /**
     * Costruisce la spec dalle risposte utente
     */
    public static function buildSpec(array $responses): ?string
    {
        $table = $responses[0] ?? null;
        $column = $responses[1] ?? null;

        if (!$table || !$column) {
            return null;
        }

        return "lookup:{$table}:{$column}";
    }
}
```

## Registrare Transform Custom

### Metodo 1: Via Config (Raccomandato)

In `config/inflow.php`:

```php
'transforms' => [
    'company_code' => \App\Transforms\CompanyCodeTransform::class,
    'currency_convert' => \App\Transforms\CurrencyConvertTransform::class,
    'lookup' => \App\Transforms\LookupTransform::class,
],
```

### Metodo 2: Via ServiceProvider

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use InFlow\Transforms\TransformEngine;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $engine = app(TransformEngine::class);
        
        // Registra istanza (per transform semplici)
        $engine->register('company_code', new \App\Transforms\CompanyCodeTransform());
        
        // Registra classe (per transform parametrizzati)
        $engine->registerClass('currency_convert', \App\Transforms\CurrencyConvertTransform::class);
    }
}
```

## Usare Transform Custom

Una volta registrati, i transform sono disponibili ovunque:

### Nel mapping JSON

```json
{
    "columns": [
        {
            "source": "product_code",
            "target": "sku",
            "transforms": ["trim", "company_code"]
        },
        {
            "source": "price_eur",
            "target": "price_usd",
            "transforms": ["currency_convert:EUR:USD:1.08"]
        }
    ]
}
```

### Nel mapping builder (codice)

```php
$mapping->column('product_code', 'sku')
    ->transform('trim')
    ->transform('company_code');

$mapping->column('price_eur', 'price_usd')
    ->transform('currency_convert:EUR:USD:1.08');
```

## Accesso al Context

Ogni transform riceve un array `$context` con informazioni sulla riga corrente:

```php
public function transform(mixed $value, array $context = []): mixed
{
    // Accedi ad altre colonne della stessa riga
    $otherColumn = $context['row']['other_column'] ?? null;
    
    // Logica condizionale
    if ($otherColumn === 'special') {
        return $this->handleSpecialCase($value);
    }
    
    return $value;
}
```

## Best Practices

1. **Naming**: Usa nomi descrittivi in snake_case (`company_code`, non `cc`)
2. **Null handling**: Gestisci sempre `null` e stringhe vuote
3. **Immutabilità**: Non modificare lo stato interno durante `transform()`
4. **Logging**: Usa `\inflow_report()` per errori/warning
5. **Testing**: Scrivi unit test per ogni transform

## Override Transform Built-in

Puoi sovrascrivere un transform built-in registrandone uno con lo stesso nome:

```php
// config/inflow.php
'transforms' => [
    'trim' => \App\Transforms\MyCustomTrimTransform::class, // Override built-in
],
```

I transform custom hanno priorità sui built-in.

## Limitazioni

I Transform possono solo:
- ✅ Manipolare il valore ricevuto
- ✅ Accedere al context (riga corrente)
- ✅ Chiamare servizi esterni (DB, API, ecc.)

I Transform **non** possono:
- ❌ Saltare o interrompere l'import di una riga
- ❌ Modificare altre colonne direttamente
- ❌ Accedere alla configurazione del mapping

Per logiche più complesse (validazione, skip conditions), vedi la documentazione su Validators e Hooks (in sviluppo).

