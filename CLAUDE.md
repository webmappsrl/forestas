# Laravel PostGIS Boilerplate — CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Stack: Laravel 12, PHP 8.4, PostgreSQL + PostGIS, Nova 5, Elasticsearch 8, Redis, Horizon.

## Comandi utili

```bash
# Formattazione codice
composer format

# Avvio ambiente locale completo (serve + horizon + pail + vite)
composer dev

# Entrare nel container PHP
docker exec -it php-${APP_NAME} bash

# Eseguire un comando artisan senza entrare nel container
docker exec -it php-${APP_NAME} php artisan <comando>

# Test (Pest)
vendor/bin/pest
vendor/bin/pest --filter=<nome-test>

# PHPStan
vendor/bin/phpstan analyse

# Pubblicare migrazioni dal wm-package
php artisan vendor:publish --tag=wm-package-migrations
```

## Setup progetto

Usare lo script `scripts/install.sh` per l'installazione guidata completa, oppure manualmente:

```bash
# 1. Configurare .env (copiare da .env-example)
cp .env-example .env
# Modificare: APP_NAME, DOCKER_PHP_PORT, DOCKER_PROJECT_DIR_NAME

# 2. Avviare Docker
bash docker/init-docker.sh

# 3. Installare dipendenze e configurare Laravel
docker exec -it php-${APP_NAME} composer install
docker exec -it php-${APP_NAME} php artisan key:generate
docker exec -it php-${APP_NAME} php artisan optimize
docker exec -it php-${APP_NAME} php artisan vendor:publish --tag=wm-package-migrations
docker exec -it php-${APP_NAME} php artisan migrate

# 4. Creare ruoli base
docker exec -it php-${APP_NAME} php artisan tinker --execute="
foreach (['Administrator', 'Editor', 'Validator', 'Guest'] as \$name) {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => \$name, 'guard_name' => 'web']);
}
"

# 5. Creare utente Administrator
docker exec -it php-${APP_NAME} php artisan nova:user
```

## Ambienti Docker

Il progetto ha tre file compose con scopi distinti:

| File | Scopo |
|------|-------|
| `compose.yml` | Base condivisa (prod). Non si usa direttamente. |
| `develop.compose.yml` | Sviluppo locale con nginx/proxy. Aggiunge minio, mailpit. |
| `local.compose.yml` | Sviluppo locale standalone con `php artisan serve`. Aggiunge scout-init, kibana, laravel server. |

```bash
# Produzione
docker compose up -d

# Sviluppo (con nginx)
docker compose -f develop.compose.yml up -d

# Sviluppo standalone (senza nginx)
docker compose -f local.compose.yml up -d
```

### Convenzioni container name

I container usano trattino come separatore: `php-${APP_NAME}`, `postgres-${APP_NAME}`, `horizon-${APP_NAME}`, `minio-${APP_NAME}`.

## Stack Elasticsearch

La sequenza di avvio è: `elasticsearch` → `elasticsearch-init` → `kibana`.

`elasticsearch-init` è un container curl one-shot che imposta la password di `kibana_system` via API (Elasticsearch non supporta variabili d'ambiente per questo utente). Si rimuove automaticamente dopo l'esecuzione.

`scout-init` (solo `local.compose.yml`) esegue `scout:import` sui modelli di wm-package dopo che Elasticsearch e il database sono pronti.

Variabili `.env` rilevanti:
```
ELASTICSEARCH_HOST=elasticsearch:9200
ELASTICSEARCH_USER=elastic
ELASTICSEARCH_PASSWORD=changeme
ELASTICSEARCH_SSL_VERIFICATION=false
DOCKER_KIBANA_PORT=5601
```

## Nova

### Gate

`NovaServiceProvider::gate()` blocca i Guest da Nova:
```php
return !$user->hasRole('Guest');
```

### Menu

Il menu è strutturato per sezioni in `NovaServiceProvider::boot()`. Le sezioni Admin e Media sono visibili solo agli Administrator. Aggiungere nuove sezioni dopo quella Media.

### Traits disponibili (`app/Nova/Traits/`)

- `FiltersUsersByRoleTrait` — filtra gli utenti relatibili per ruolo (Administrator/Validator)
- `HidesAppFromIndexTrait` — nasconde il campo `app` dalla lista index

### Footer

Il footer Nova viene renderizzato da `resources/views/nova/footer.blade.php` e mostra: nome app, versione, environment, versioni di Nova/Laravel/PHP.

### Estensione risorse wm-package

Le risorse Nova nel progetto estendono quelle del wm-package. Pattern:
```php
namespace App\Nova;

use Wm\WmPackage\Nova\App as WmNovaApp;

class App extends WmNovaApp {}
```

Questo permette di personalizzare label, campi, o aggiungere funzionalità mantenendo la logica base nel package.

## Ruoli e Permessi

Il sistema usa spatie/laravel-permission tramite wm-package. Ruoli predefiniti:
- **Administrator** — accesso completo a Nova, gestione utenti e app
- **Editor** — creazione e modifica contenuti
- **Validator** — validazione UGC
- **Guest** — solo lettura, NO accesso a Nova (bloccato dal gate)

Le policy di Role e Permission sono registrate in `AppServiceProvider::boot()`. Per aggiungere policy progetto-specifiche:
```php
Gate::policy(MyModel::class, MyModelPolicy::class);
```

## PHPStan

```bash
vendor/bin/phpstan analyse
```

Configurazione in `phpstan.neon.dist`. La baseline è `phpstan-baseline.neon`. Livello 5.

## MinIO e Storage

MinIO è disponibile negli ambienti di sviluppo per simulare S3. Endpoint: `http://localhost:${FORWARD_MINIO_PORT}` (default 9000). Console: port 8900.

Credenziali default: `laravel` / `laravelminio`. Bucket: `wmfe`.

Il sistema di icone globale è gestito tramite `GlobalFileHelper` (wm-package) che carica e mantiene aggiornato `icons.json` in MinIO.

## Testing

Il progetto usa Pest. Configurazione in `phpunit.xml`:
```bash
# Eseguire tutti i test
vendor/bin/pest

# Eseguire un file specifico
vendor/bin/pest tests/Feature/EsempioTest.php

# Con filtro
vendor/bin/pest --filter=nome_test
```

Variabili d'ambiente di testing definite in `phpunit.xml`.

### Regole obbligatorie per i test

**NON eseguire mai i test senza prima verificare l'isolamento del DB.** Prima di lanciare qualsiasi test:

1. Verificare `phpunit.xml` — il `DB_DATABASE` deve puntare a un DB separato dal DB reale.
2. Se il DB di test non è chiaramente isolato, NON lanciare i test — chiedere consenso esplicito all'utente.
3. Questa regola vale anche per i subagent: istruirli esplicitamente a non lanciare test senza verifica isolamento.

**Il DB reale contiene dati importati da processi lunghi — distruggerli è inaccettabile.**

## Regole operative

### Commit

**Non eseguire mai `git commit` senza istruzione esplicita dell'utente.** Implementa, testa, poi fermati. È l'utente che controlla il codice e committa. Questa regola vale anche per i subagent: istruirli sempre a non committare.

### wm-package CLAUDE.md

Leggere sempre `wm-package/CLAUDE.md` quando si lavora su codice che tocca il package.

## wm-package (submodule)

Il progetto dipende da `wm/wm-package` come path repository (submodule Git in `../wm-package` o `vendor/wm/wm-package`).

Il package fornisce:
- Modelli base (User, EcTrack, EcPoi, UgcTrack, UgcPoi, Layer, App)
- Risorse Nova base
- Policy Role/Permission
- Comandi artisan personalizzati
- Migrazioni (da pubblicare con `--tag=wm-package-migrations`)

Quando si modifica il wm-package, ricordare che è condiviso tra progetti.
