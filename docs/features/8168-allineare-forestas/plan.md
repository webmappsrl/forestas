> Ticket: oc:8168

# Plan — Allineare FORESTAS

## Task 1 — Ricostruire autoloader nel container

**Repo:** forestas (container php-forestas)

```bash
docker exec -it php-forestas composer install
```

Verifica che l'output non contenga errori. Al termine, testa il boot:

```bash
docker exec -it php-forestas php artisan about
```

Atteso: nessun errore `Class not found`. Se il boot fallisce, leggere lo stacktrace prima di procedere.

---

## Task 2 — Pubblicare migration stubs mancanti

**Repo:** forestas

```bash
docker exec -it php-forestas php artisan vendor:publish --tag=wm-package-migrations
```

Dopo l'esecuzione, verificare i file creati in `database/migrations/` — attesi almeno:
- `*_create_tiles_table.php`
- `*_create_app_tile_table.php`
- `*_add_editor_role.php`

Verificare anche se compaiono file per `taxonomy_themes` o `taxonomy_themeables` (stub presenti nel package ma potenzialmente non ancora pubblicati). Se compaiono, sono attesi e devono essere migrati.

---

## Task 3 — Applicare le migrazioni al DB

**Repo:** forestas

Prima di migrare, verificare lo stato:

```bash
docker exec -it php-forestas php artisan migrate:status | grep -E "tiles|app_tile|editor_role|taxonomy_theme"
```

Se nessuna delle nuove migration risulta già eseguita, procedere:

```bash
docker exec -it php-forestas php artisan migrate
```

Se `create_app_tile_table` fallisce sul backfill, verificare il formato della colonna `apps.tiles` nel DB e adattare la migrazione se necessario (annotare in notes.md).

---

## Task 4 — Aggiungere stub Nova: Tile

**Repo:** forestas — `app/Nova/Tile.php`

```php
<?php

namespace App\Nova;

use Wm\WmPackage\Nova\Tile as WmNovaTile;

class Tile extends WmNovaTile {}
```

---

## Task 5 — Aggiungere stub Nova: TaxonomyTheme

**Repo:** forestas — `app/Nova/TaxonomyTheme.php`

```php
<?php

namespace App\Nova;

use Wm\WmPackage\Nova\TaxonomyTheme as WmNovaTaxonomyTheme;

class TaxonomyTheme extends WmNovaTaxonomyTheme {}
```

---

## Task 6 — Registrare TilePolicy in AppServiceProvider

**Repo:** forestas — `app/Providers/AppServiceProvider.php`

Aggiungere:

```php
use Wm\WmPackage\Models\Tile;
use Wm\WmPackage\Policies\TilePolicy;
```

E nel metodo `boot()`:

```php
Gate::policy(Tile::class, TilePolicy::class);
```

---

## Task 7 — Registrare nuove risorse nel menu Nova

**Repo:** forestas — `app/Providers/NovaServiceProvider.php`

L'utente indicherà dove inserire `Tile` e `TaxonomyTheme` nel menu. Attendere indicazione prima di modificare il file.

Aggiungere gli import:

```php
use App\Nova\Tile as NovaTile;
use App\Nova\TaxonomyTheme as NovaTaxonomyTheme;
```

---

## Task 8 — Aggiornare puntatore submodule in forestas

**Repo:** forestas

```bash
git add wm-package
```

Verificare con `git diff --cached` che il puntatore sia passato da `0cff8d34` a `a94a634f`.

Il commit verrà eseguito insieme agli altri file modificati nella fase di review gate.

---

## Task 9 — Verifica finale

```bash
docker exec -it php-forestas php artisan about
docker exec -it php-forestas php artisan migrate:status | tail -20
```

Controllare visivamente Nova nel browser (se disponibile) per verificare che le nuove risorse compaiano senza errori 500.

---

## Commit convention

```
feat(oc:8168): align forestas to wm-package a94a634f
```
