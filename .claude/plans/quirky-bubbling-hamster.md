# Fix: Import come sync affidabile

## Contesto

L'import deve funzionare come sync bidirezionale: aggiornare dati esistenti, aggiungere nuovi, e gestire rimozioni/svuotamenti dall'API. Attualmente ci sono bug che impediscono una sync corretta.

## Problemi identificati

### P1 — `sync([])` mai chiamato su relazioni vuote
Se l'API rimuove tutte le tassonomie da un POI/Track, o rimuove partenza/arrivo, le vecchie relazioni restano perché il codice fa `if (!empty($ids)) { sync($ids) }` — salta il caso vuoto.

**Fix:** Chiamare sempre `sync()`, anche con array vuoto.

**File:** `app/Services/Import/SardegnaSentieriImportService.php`
- `syncPoiTaxonomies()` (riga ~253): rimuovere il `if (!empty(...))`
- `syncTrackTaxonomies()` (riga ~417): rimuovere il `if (!empty(...))`
- `syncRelatedPois()` (riga ~396): rimuovere il `if (!empty(...))`

### P2 — GPX con namespace XML non parsato
I file GPX standard hanno `xmlns="http://www.topografix.com/GPX/1/1"`. SimpleXML non trova `$xml->trk` senza registrare il namespace → geometry sempre null, silenziosamente.

**Fix:** In `parseGpxToWkt()`, dopo `simplexml_load_string()`, registrare il namespace di default e usare `children()` oppure strip del namespace prima del parsing.

Approccio più semplice — strip namespace:
```php
$gpxContent = preg_replace('/xmlns\s*=\s*"[^"]*"/', '', $gpxContent, 1);
```

**File:** `app/Services/Import/SardegnaSentieriImportService.php` — metodo `parseGpxToWkt()` (riga ~350)

### P3 — Doppia query in `importPoi()`
La whereRaw per cercare il POI esistente viene eseguita 2 volte (riga ~217 per `value('properties')` e riga ~232 per `first()`). Unificare in una sola query.

**Fix:** fare `first()` una volta, estrarre properties da lì.

### P4 — Doppia query in `importTrack()`
Stesso pattern: riga ~294 `value('properties')` e riga ~319 `firstWhere()`.

**Fix:** stesso approccio del P3.

### P5 — Gestione item rimossi dall'API
Se un POI/Track viene eliminato dall'API, resta nel DB per sempre. Serve un meccanismo di soft-delete/disabilitazione.

**Fix:** Nel command, dopo il dispatch dei job, confrontare gli ID dell'API con quelli nel DB. Marcare come `properties->forestas->deleted_from_source = true` quelli non più presenti nell'API. Non eliminarli fisicamente.

**File:** `app/Console/Commands/ImportSardegnaSentieriCommand.php` — aggiungere logica dopo `importPois()` e `importTracks()`

### P6 — Client timeout basso per GPX pesanti
Il client ha `TIMEOUT = 30s` per tutto, ma i GPX possono essere grossi.

**Fix:** `getGpxContent()` deve usare un timeout più alto (es. 60s).

**File:** `app/Http/Clients/SardegnaSentieriClient.php` — metodo `getGpxContent()` (riga ~106)

## Verifica

```bash
# PHPStan
vendor/bin/phpstan analyse app/Services/Import/ app/Http/Clients/ app/Console/Commands/

# Test sync: import, poi re-import per verificare idempotenza
docker exec -it php-${APP_NAME} php artisan sardegnasentieri:import --only=pois --force
docker exec -it php-${APP_NAME} php artisan sardegnasentieri:import --only=pois  # secondo run: 0 dispatched

# Test GPX: import di una track con GPX
docker exec -it php-${APP_NAME} php artisan tinker --execute="
  app(\App\Services\Import\SardegnaSentieriImportService::class)->importTrack(75);
"
```
