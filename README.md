# Laravel InFlow

Motore ETL dev-first per Laravel.

## Installazione (sviluppo locale)

Questo package è in sviluppo. Per installarlo in un'app Laravel locale:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/inflow/etl"
        }
    ],
    "require": {
        "fabio-guin/laravel-inflow": "@dev"
    }
}
```

## Testing

```bash
# From the Laravel app root using Sail
./vendor/bin/sail exec laravel.test php packages/inflow/etl/vendor/bin/phpunit --configuration packages/inflow/etl/phpunit.xml

# Or from the package directory
cd packages/inflow/etl
./vendor/bin/phpunit
```

## Stato

🚧 In sviluppo - Fase 0

