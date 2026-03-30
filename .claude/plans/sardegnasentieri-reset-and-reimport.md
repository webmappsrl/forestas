# Piano: Parametro `--reset` su `sardegnasentieri:import`

## Contesto

Aggiungere `--reset` a `ImportSardegnaSentieriCommand` per fare truncate+reimport in un colpo solo.

Il reset cancella (in ordine):
1. `TaxonomyActivity` con identifier creati dall'import (via `app_id` indiretta — vedere sotto)
2. `TaxonomyPoiType` idem
3. `EcTrack` con `app_id = IMPORT_APP_ID` (con detach di taxonomyActivities + ecPois)
4. `EcPoi` con `app_id = IMPORT_APP_ID` (con detach di taxonomyPoiTypes)

Le immagini Spatie (`media` table) **NON vengono toccate** — vengono gestite dai MediaJob come sempre.

Il parametro `--reset` implica automaticamente `--force` (dopo reset tutti i record sono nuovi).

---

## Phase 0 — Documentazione e pattern esistenti

### File rilevanti
- `app/Console/Commands/ImportSardegnaSentieriCommand.php` — aggiungere `--reset` qui
- `app/Console/Commands/ResetSardegnaSentieriCommand.php` — logica di delete da copiare
- `app/Services/Import/SardegnaSentieriImportService.php` — costante `IMPORT_APP_ID`, metodi importAll()

### Pattern da seguire
- `ResetSardegnaSentieriCommand::handle()` — detach + delete per EcTrack e EcPoi
- `ImportSardegnaSentieriCommand::handle()` — struttura esistente con `$this->option()`
- Costante `SardegnaSentieriImportService::IMPORT_APP_ID` per identificare i record

### Come identificare le tassonomie da cancellare

TaxonomyPoiType e TaxonomyActivity **non hanno `app_id`**. Sono identificate dall'`identifier` nel formato
`sardegnasentieri_<id>` (impostato dal service in `importPoiTaxonomies` / `importTrackTaxonomies`).

**Verifica prima di implementare:**
```bash
# Controllare il formato identifier nelle tassonomie create
grep -n "identifier" app/Services/Import/SardegnaSentieriImportService.php
```

Se l'identifier non ha un prefisso univoco, usare approccio alternativo: cancellare solo le tassonomie
che **non hanno più relazioni** con EcPoi/EcTrack di altre app dopo la pulizia dei record.

> **Anti-pattern**: NON fare `TaxonomyPoiType::truncate()` globale — potrebbe cancellare tassonomie di
> altri progetti se la DB è condivisa.

---

## Phase 1 — Implementazione

### Task 1.1 — Aggiungere `--reset` alla signature

In `ImportSardegnaSentieriCommand.php`, aggiungere il parametro alla signature:

```php
protected $signature = 'sardegnasentieri:import
                        {--force : Reimport all ignoring updated_at}
                        {--only= : Import only pois or tracks}
                        {--reset : Delete all imported data before importing (implies --force)}';
```

### Task 1.2 — Aggiungere i use statement necessari

Aggiungere in cima al file se non già presenti:
```php
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\TaxonomyActivity;
```

(EcPoi, EcTrack già presenti nel file.)

### Task 1.3 — Aggiungere metodo `resetData()` privato

Aggiungere in `ImportSardegnaSentieriCommand`. Si usa `TRUNCATE ... RESTART IDENTITY CASCADE`
per azzerare anche le sequenze (auto-increment). Le tabelle junction vengono svuotate dalla CASCADE.

```php
private function resetData(): void
{
    // Ordine: prima le tabelle con FK verso ec_pois/ec_tracks, poi le principali
    DB::statement('TRUNCATE TABLE ec_tracks RESTART IDENTITY CASCADE');
    $this->info('Truncated ec_tracks.');

    DB::statement('TRUNCATE TABLE ec_pois RESTART IDENTITY CASCADE');
    $this->info('Truncated ec_pois.');

    DB::statement('TRUNCATE TABLE taxonomy_poi_types RESTART IDENTITY CASCADE');
    $this->info('Truncated taxonomy_poi_types.');

    DB::statement('TRUNCATE TABLE taxonomy_activities RESTART IDENTITY CASCADE');
    $this->info('Truncated taxonomy_activities.');
}
```

> **Nota CASCADE**: svuota automaticamente anche le junction table
> (`ec_track_taxonomy_activity`, `ec_poi_taxonomy_poi_type`, `ec_poi_ec_track`, ecc.).
> I media Spatie (tabella `media`) non hanno FK verso ec_pois/ec_tracks quindi non vengono toccati.
>
> **Assunzione**: nessun altro record in ec_pois/ec_tracks da preservare — verificato con l'utente.

### Task 1.4 — Chiamare `resetData()` in `handle()` prima dell'import

In `handle()`, dopo `ensurePrerequisites()` e prima di `importAll()`:

```php
if ($this->option('reset')) {
    $this->info('Resetting all imported data...');
    $this->resetData();
    $this->info('Reset completed.');
}
```

### Task 1.5 — `--reset` implica `--force`

Il filtro `updated_at` è usato in `importPois()` e `importTracks()` tramite `$this->option('force')`.
Sostituire ogni chiamata a `$this->option('force')` con:

```php
$force = $this->option('force') || $this->option('reset');
```

Oppure, all'inizio di `handle()`, resolvere una sola volta:
```php
$isForce = $this->option('force') || $this->option('reset');
```
e passarla ai metodi privati.

---

## Phase 2 — Verifica

### Checklist
- [ ] `php artisan sardegnasentieri:import --help` mostra `--reset`
- [ ] PHPStan: `vendor/bin/phpstan analyse app/Console/Commands/ImportSardegnaSentieriCommand.php`
- [ ] Test manuale con DB: verificare che dopo `--reset` EcPoi/EcTrack/tassonomie siano vuote
- [ ] Verificare che `--reset` senza `--force` esplicito bypassa comunque il filtro `updated_at`
- [ ] Verificare che i media Spatie NON vengano cancellati

### Comandi di verifica
```bash
# PHPStan
vendor/bin/phpstan analyse app/Console/Commands/

# Test import parziale dopo reset
php artisan sardegnasentieri:import --reset --only=pois

# Verifica scheduler invariato
php artisan schedule:list
```

---

## Note di compatibilità

- `ResetSardegnaSentieriCommand` rimane invariato — utile per reset senza reimport
- Lo scheduler (`dailyAt('03:00')`) chiama `sardegnasentieri:import` senza `--reset` — comportamento invariato
- Il parametro `--reset` è **opzionale e manuale** — non viene mai chiamato automaticamente
