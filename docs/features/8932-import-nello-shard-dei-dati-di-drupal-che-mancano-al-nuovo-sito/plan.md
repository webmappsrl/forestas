> Ticket: oc:8932

# Import dei dati Drupal mancanti — piano di implementazione

> **Per chi esegue:** REQUIRED SUB-SKILL: usa `superpowers:subagent-driven-development` (consigliata)
> oppure `superpowers:executing-plans` per eseguire il piano task per task. Gli step usano le
> caselle (`- [ ]`) per tenere il segno.

**Obiettivo:** portare nello shard i dati di Drupal che il nuovo sito WordPress deve mostrare e che
oggi mancano o arrivano in una forma inutilizzabile.

**Approccio:** si estende la sorgente (`SardegnaSentieriDatabaseSource`) a leggere i campi Drupal
oggi ignorati, e il service a scriverli. I collegamenti fra contenuti smettono di essere scritti dal
job del singolo contenuto e passano a un job di risoluzione di fine batch, che li scrive in un punto
solo. I sentieri correlati fra loro non si importano più: si calcolano dalla geometria.

**Stack:** Laravel 12, PHP 8.4 nel container `php-forestas`, PostgreSQL + PostGIS, Nova 5.7.6,
Pest. Submodule `wm-package`.

**Spec:** [overview.md](overview.md)

## Vincoli globali

- Identificatori nel codice **sempre in inglese**; commenti, documentazione e messaggi di commit in
  **italiano**.
- Le geometrie PostGIS passano **sempre da SQL puro**, mai dall'ORM. Ogni confronto di distanza usa
  il cast: `ST_DWithin(a.geometry::geography, b.geometry::geography, <metri>)`. Senza `::geography`
  il numero è interpretato in **gradi**.
- Il raccordo fra nodo Drupal e record dello shard usa chiavi **diverse sui due modelli**:
  `properties->>'sardegnasentieri_id'` su `ec_tracks`, `properties->>'out_source_feature_id'` su
  `ec_pois`.
- Un dato Drupal non ancora usato si prende dal record completo di `SardegnaSentieriDatabaseSource`,
  mai con una query nuova.
- Nelle query dell'import **non si filtra `status = 1`**: le bozze vanno importate.
- Le date della copia Drupal si convertono **in PHP**, mai con `FROM_UNIXTIME`.
- Gli URL dei file si codificano **segmento per segmento con `rawurlencode`**: l'URL è la chiave con
  cui `SardegnaSentieriMediaSyncService` riconosce un file già scaricato.
- I job dell'import stanno sulla coda `sardegnasentieri-import`. Dopo aver modificato un job:
  `docker restart horizon-forestas`.
- **Prima di lanciare i test leggi `.claude/rules/test.md`** e verifica l'isolamento del database.
- Dove una tabella è creata da una migration di questo repo si **modifica la `create`**, non si
  aggiunge una migration: UAT lo aggiorna il team a mano.
- **Nessun commit automatico.** I passi «Commit» sono istruzioni per il dev.
- I due task sul `wm-package` vanno per primi e si mergiano nel package prima del resto: è la regola
  del submodule.

## Review Focus

Casi che nessun test di task copre da sé e che si rompono per primi davanti a un utente:

1. **Nodo citato mai importato** — un contenuto cita un nodo cancellato o mai arrivato: la
   risoluzione deve ignorarlo senza far fallire il batch (coperto in Task 9).
2. **Import orario, nodo citato fuori dal batch** — solo i nodi con `changed` mosso vengono
   importati: la risoluzione deve cercare nel database (coperto in Task 9).
3. **File oltre il limite generale** — un allegato da 30 MB deve entrare benché il limite dello
   shard sia 20 MB (coperto in Task 3).
4. **Contenuto senza traduzione inglese** — il ripiego sull'inglese non deve rompere i contenuti che
   l'inglese non ce l'hanno (coperto in Task 5).
5. **Percorso isolato** — un sentiero che non tocca nessun altro non deve finire con collegamenti
   fasulli né far fallire il calcolo (coperto in Task 10).

---

### Task 1 (wm-package): `related_url` su `EcTrack`

**File:**
- Modifica: `wm-package/src/Models/EcTrack.php` (righe commentate di `related_url`)
- Modifica: `wm-package/src/Nova/EcTrack.php`
- Test: `wm-package/tests/Feature/Models/EcTrackRelatedUrlTest.php`

**Interfacce:**
- Produce: il valore `label => url` dentro **`properties['related_url']`**, più il campo Nova che lo
  mostra. Nessuna colonna, e **nessun `fillable` né cast**: verificato a runtime che su `EcPoi`
  `$poi->related_url = [...]` seguito da `save()` fallisce con
  `SQLSTATE 42703: column "related_url" of relation "ec_pois" does not exist`. Il `fillable` e il
  cast presenti su `EcPoi` sono inerti. Chi scrive questo dato — Task 8 — passa da `properties`,
  mai da un attributo del modello.

- [ ] **Step 1: scrivi il test che fallisce**

```php
it('salva related_url dentro properties, come su EcPoi', function () {
    $track = EcTrack::factory()->create();
    $track->related_url = ['Sito del Parco' => 'https://www.parcotepilora.it'];
    $track->save();

    expect($track->fresh()->properties['related_url'])
        ->toBe(['Sito del Parco' => 'https://www.parcotepilora.it']);
});
```

- [ ] **Step 2: lancia il test e verifica che fallisca**

Da `wm-package/`: `vendor/bin/pest tests/Feature/Models/EcTrackRelatedUrlTest.php`
Atteso: FAIL, `related_url` non è fillable.

- [ ] **Step 3: dichiara il campo su `EcTrack`**

Scommenta `'related_url'` in `$fillable` e `'related_url' => 'array'` nei cast, come sono su
`wm-package/src/Models/EcPoi.php:31,63`. Verifica che il meccanismo che riversa gli attributi dentro
`properties` sia lo stesso dei due modelli: se `EcPoi` lo ottiene da un trait, `EcTrack` deve usarlo.

- [ ] **Step 4: aggiungi il campo Nova**

In `wm-package/src/Nova/EcTrack.php`, accanto agli altri campi di `properties`:
`KeyValue::make(__('Related URL'), 'properties->related_url')`, com'è in
`wm-package/src/Nova/EcPoi.php:94`.

- [ ] **Step 5: lancia il test e verifica che passi**

- [ ] **Step 6: commit**

```bash
# nel submodule wm-package
git add src/Models/EcTrack.php src/Nova/EcTrack.php tests/Feature/Models/EcTrackRelatedUrlTest.php
git commit -m "feat(oc:8932): related_url anche su EcTrack"
```

---

### Task 2 (wm-package): la copertina non può essere un PDF

**File:**
- Modifica: `wm-package/src/Models/EcTrack.php:732`
- Test: `wm-package/tests/Feature/Models/EcTrackFeatureImageTest.php`

**Interfacce:**
- Consuma: niente.
- Produce: `toSearchableArray()['feature_image']` ricavato dal primo media della collection
  **`default`** — quella in cui il package scrive le immagini — il cui `mime_type` comincia per
  `image/`. Il criterio è doppio di proposito: la collection è la difesa, il tipo è la rete di
  sicurezza se in `default` finisce qualcosa che immagine non è.

- [ ] **Step 1: scrivi i due test che falliscono**

```php
it('non usa un documento come feature_image', function () {
    $track = EcTrack::factory()->create();
    // media non-immagine aggiunto per primo, immagine per seconda
    expect($track->toSearchableArray()['feature_image'])->not->toContain('.pdf');
});

it('continua a usare la prima immagine quando ci sono solo immagini', function () {
    // comportamento invariato per gli shard che hanno solo immagini
});
```

- [ ] **Step 2: lancia i test e verifica che il primo fallisca**

- [ ] **Step 3: leggi dalla collection delle immagini**

Alla riga 732 sostituisci `$this->getMedia('*')` con `$this->getMedia('default')`, e fra quei media
prendi il primo il cui `mime_type` comincia per `image/`. `default` è la collection in cui il
package stesso scrive le immagini dei contenuti editoriali (`EcMediaImportService.php:83`,
`GeohubImportService.php:1228`); il controllo sul tipo copre il caso in cui in `default` finisca
qualcosa che immagine non è.

Perché `'*'` è il difetto: in Spatie, `InteractsWithMedia::loadMedia()` (riga 651) fa
`->filter(fn ($m) => $collectionName !== '*' ? $m->collection_name === $collectionName : true)->sortBy('order_column')`.
Con l'asterisco il filtro sulla collection non si applica: arrivano anche i documenti e i video che
questo ticket aggiunge, e `->first()` prende quello con `order_column` più basso.

- [ ] **Step 4: lancia i test e verifica che passino entrambi**

- [ ] **Step 5: commit**

```bash
git add src/Models/EcTrack.php tests/Feature/Models/EcTrackFeatureImageTest.php
git commit -m "fix(oc:8932): la feature_image di EcTrack ignora i media che non sono immagini"
```

> Dopo il merge nel package: bump del puntatore del submodule in forestas, e `scout:import` delle
> tracce sugli shard dove la copertina cambia.

---

### Task 3: il limite di 80 MB solo per i file dell'import

**File:**
- Modifica: `app/Services/Import/SardegnaSentieriMediaSyncService.php`
- Test: `tests/Feature/Import/MediaSizeLimitTest.php`

**Interfacce:**
- Produce: una costante `SardegnaSentieriMediaSyncService::IMPORT_MAX_FILE_SIZE` = `1024 * 1024 * 80`
  e il limite applicato solo durante lo scaricamento dell'import.

- [ ] **Step 1: scrivi i due test che falliscono**

```php
it('accetta un file da 30 MB benché il limite dello shard sia 20', function () { /* … */ });
it('lascia invariato il limite generale dopo lo scaricamento', function () {
    expect(config('media-library.max_file_size'))->toBe(1024 * 1024 * 20);
});
```

- [ ] **Step 2: lancia i test e verifica che il primo fallisca**

Atteso: `FileIsTooBig`.

- [ ] **Step 3: alza il limite per la sola durata dello scaricamento**

Nel punto in cui il service aggiunge il media, imposta `config(['media-library.max_file_size' =>
self::IMPORT_MAX_FILE_SIZE])` e **ripristina il valore precedente in un `finally`**, così un'eccezione
non lascia il limite alzato per il resto del processo. Non creare `config/wm-media-library.php`: il
package riversa quel file dentro `media-library` (`WmPackageServiceProvider.php:589`) e alzerebbe il
limite anche per le foto dall'app e per gli upload in Nova.

- [ ] **Step 4: lancia i test e verifica che passino**

- [ ] **Step 5: commit** — `feat(oc:8932): limite di 80 MB per i soli file scaricati dall'import`

---

### Task 4: il `name` delle tipologie dai termini Drupal

**File:**
- Modifica: `app/Services/Import/SardegnaSentieriImportService.php:1577-1600` (`buildTaxonomyData`)
- Test: `tests/Feature/Import/TaxonomyNamesFromDrupalTest.php`

**Interfacce:**
- Produce: `buildTaxonomyData()` restituisce `identifier` invariato (l'identificativo Geohub quando
  c'è) e `name` preso dal termine Drupal, con le lingue che Drupal ha.

- [ ] **Step 1: scrivi i tre test che falliscono**

```php
it('usa il nome del termine Drupal e non l\'identifier', function () { /* name: «Alberi monumentali», identifier: monumental-tree */ });
it('non cambia l\'identifier, così non nascono termini doppi', function () { /* findOrNewPoiType cerca per identifier */ });
it('porta la traduzione inglese quando Drupal ce l\'ha', function () { /* 9 tipologie su 21 */ });
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

- [ ] **Step 3: separa `identifier` da `name`**

In `buildTaxonomyData()` il ramo con `geohub_identifier` oggi assegna quell'identificativo a
**entrambi**. Lascia `identifier` com'è e fai arrivare in `name` il nome del termine, nelle lingue
disponibili. Dove più termini confluiscono nello stesso identifier — `crossroads` (3 termini, 234
POI) e `disabled-access` (3 termini, 8 POI) — vince il termine registrato in
`properties.source_id`, che è il comportamento già in essere.

- [ ] **Step 4: lancia i test e verifica che passino**

- [ ] **Step 5: verifica sui dati veri**

```bash
docker exec php-forestas php artisan tinker --execute="
foreach (DB::table('taxonomy_poi_types')->orderBy('identifier')->get(['identifier','name']) as \$t) echo \$t->identifier.' | '.\$t->name.PHP_EOL;"
```
Atteso: 28 righe, nessuna con `name` uguale a `identifier`.

- [ ] **Step 6: commit** — `feat(oc:8932): i tipi di POI e le attività portano il nome di Drupal`

---

### Task 5: ripiego sulla traduzione inglese per i media

**File:**
- Modifica: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php`
- Test: `tests/Feature/Import/MediaEnglishFallbackTest.php`

**Interfacce:**
- Produce: `images()`, `documentUrls()` e `videos()` leggono la lingua originale e, **solo se vuota**,
  la traduzione inglese dello stesso nodo.

- [ ] **Step 1: scrivi i tre test che falliscono**

```php
it('prende i media dalla traduzione inglese quando la lingua originale non ne ha', function () { /* nodi 75, 1105 */ });
it('ignora la traduzione inglese quando la lingua originale ha i media', function () {});
it('non fallisce su un nodo senza traduzione inglese', function () {});
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

- [ ] **Step 3: aggiungi il ripiego**

Il record di `DrupalRecordReader` contiene già tutte le lingue (`fields[campo][lingua][delta]`): il
ripiego si fa lì, senza query nuove.

- [ ] **Step 4: lancia i test e verifica che passino**

- [ ] **Step 5: scopri perché 5 immagini non arrivano**

Nodi 3956 e 3966 (la stessa foto «Gavoi visto da Loiloi»), 4601, 5066, 5441. Dopo un import completo
in locale, per ciascuno: il file esiste su Drupal, l'URL costruito dall'import risponde, il media
sync lo scarta o fallisce. **Cinque casi senza causa sono probabilmente una classe di bug** — un
carattere nel nome del file, una codifica dell'URL — non cinque incidenti: se la causa è comune, il
test che la pinna va qui e il resto del lavoro è una riga. Se invece sono cinque casi davvero
distinti, scrivilo in `notes.md` e vai avanti.

- [ ] **Step 6: lancia i test e verifica che passino**

- [ ] **Step 7: commit** — `feat(oc:8932): media dalla traduzione inglese quando la lingua originale non ne ha`

---

### Task 6: allegati e video copiati nello shard

**File:**
- Modifica: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` (allegati dei POI, oggi
  `'allegati' => []`; titolo dell'allegato)
- Modifica: `app/Services/Import/SardegnaSentieriMediaSyncService.php`
- Modifica: `app/Jobs/Import/ImportSardegnaSentieriTrackMediaJob.php`,
  `ImportSardegnaSentieriPoiMediaJob.php`
- Test: `tests/Feature/Import/AttachmentsImportTest.php`

**Interfacce:**
- Consuma: il limite di Task 3.
- Produce: allegati e video nelle collection `attachments` e `videos`; il titolo che Drupal mostra
  salvato sul media; `properties.forestas.allegati` non è più l'unica sede.

- [ ] **Step 1: scrivi i quattro test che falliscono**

```php
it('copia gli allegati di un sentiero nella collection attachments', function () {});
it('salva il titolo che Drupal mostra sull\'allegato', function () {});
it('copia gli allegati dei POI, che oggi sono sempre vuoti', function () { /* 12 POI */ });
it('copia i video caricati su Drupal, anche quello da 78 MB', function () {});
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

- [ ] **Step 3: leggi gli allegati dei POI nella sorgente**

In `getPoiDetail()` sostituisci `'allegati' => []` con la lettura di `field_allegati_media`, e porta
con ogni allegato il titolo. Il commento che dice «Le API non esponevano gli allegati dei POI» va
aggiornato: ora si legge dalla copia del database.

- [ ] **Step 4: scarica i file nelle collection dedicate**

Nel media sync, allegati in `attachments` e video in `videos`. Gli URL si codificano segmento per
segmento con `rawurlencode`.

- [ ] **Step 5: lancia i test e verifica che passino**

- [ ] **Step 6: verifica sui dati veri**

Dopo un import completo in locale: 163 allegati e 4 video nella media library, nessun errore
`FileIsTooBig` nei log.

- [ ] **Step 7: commit** — `feat(oc:8932): allegati e video copiati nello shard`

---

### Task 7: didascalia, autore e credits delle foto

**File:**
- Modifica: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` (`images()`)
- Modifica: `app/Services/Import/SardegnaSentieriMediaSyncService.php`
- Test: `tests/Feature/Import/MediaCaptionTest.php`

**Interfacce:**
- Produce: `custom_properties.sardegnasentieri_caption` sul media, accanto a
  `sardegnasentieri_autore` e `_credits` che l'import salva già.

- [ ] **Step 1: scrivi i due test che falliscono**

```php
it('salva la didascalia che Drupal mostra sotto la foto', function () { /* «Nuoro e Ortobene visti dal Monte Corrasi, Oliena» */ });
it('non sovrascrive la didascalia con il nome del file quando manca', function () {});
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

- [ ] **Step 3: leggi la didascalia dal media Drupal e salvala**

Oggi il `name` del media è il nome del file trasformato: la didascalia va in `custom_properties`,
non al posto del `name`.

- [ ] **Step 4: lancia i test e verifica che passino**

- [ ] **Step 5: commit** — `feat(oc:8932): didascalia delle foto importata da Drupal`

---

### Task 8: collegamenti esterni in `related_url`

**File:**
- Modifica: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` (`links()` anche sulle
  tracce)
- Modifica: `app/Services/Import/SardegnaSentieriImportService.php`
- Modifica: `app/Nova/EcPoi.php:83`
- Test: `tests/Feature/Import/RelatedUrlImportTest.php`

**Interfacce:**
- Consuma: il campo Nova di Task 1. **Attenzione**: non esiste l'attributo `$track->related_url` —
  assegnarlo fa fallire il `save()` con `SQLSTATE 42703`. Si scrive dentro `properties`, come fa
  l'import per gli altri dati in `properties.forestas`.
- Produce: `properties['related_url']` come `label => url` su percorsi e punti di interesse.

- [ ] **Step 1: scrivi i tre test che falliscono**

```php
it('converte l\'array piatto di Drupal in label => url', function () {
    // ['http://www.wwf.it/…', 'WWW oasi di monte Arcosu'] => ['WWW oasi di monte Arcosu' => 'http://www.wwf.it/…']
});
it('importa i collegamenti degli itinerari', function () { /* 75 */ });
it('regge un collegamento senza titolo', function () {});
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

- [ ] **Step 3: converti e scrivi**

Usa il trait `NormalizesRelatedUrl` del `wm-package`, che fa già questa normalizzazione per gli
import GeoHub ed Excel.

- [ ] **Step 4: fai puntare il campo Nova alla destinazione nuova**

`app/Nova/EcPoi.php:83` mostra oggi `properties->forestas->collegamenti`: va su
`properties->related_url`, altrimenti resta vuoto.

- [ ] **Step 5: lancia i test e verifica che passino**

- [ ] **Step 6: commit** — `feat(oc:8932): collegamenti esterni in related_url su percorsi e POI`

---

### Task 9: risoluzione dei riferimenti in un punto solo

**File:**
- Crea: `app/Jobs/Import/ResolveSardegnaSentieriRelationsJob.php`
- Modifica: `app/Services/Import/SardegnaSentieriImportService.php:776-801` (`syncRelatedPois`),
  `:1169-1187` (`syncRelatedPoisForPoi`)
- Modifica: `app/Console/Commands/ImportSardegnaSentieriCommand.php:302` (catena di fine batch)
- Modifica: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` (legge
  `field_punti_di_interesse` degli itinerari e `field_sentieri_collegati` /
  `field_itinerari_correlati` dei POI)
- Test: `tests/Feature/Import/ResolveRelationsJobTest.php`

**Interfacce:**
- Produce: `ResolveSardegnaSentieriRelationsJob`, sulla coda `sardegnasentieri-import`, che scrive
  `ec_poi_ec_track` e `ec_poi_related_pois` con **un `sync()` per contenuto**.

- [ ] **Step 1: scrivi i sei test che falliscono**

```php
it('unisce i collegamenti scritti dal percorso e quelli scritti dal POI', function () {});
it('non perde i collegamenti di un contenuto importato prima di quello citato', function () {});
it('cerca nel database e non fra i contenuti del batch', function () { /* import orario */ });
it('ignora un nodo citato che non è mai stato importato', function () {});
it('scarta le coppie oltre i 10 km misurati sulla geometria', function () {});
it('rimuove un collegamento sparito alla fonte', function () { /* sync, non syncWithoutDetaching */ });
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

- [ ] **Step 3: leggi i campi mancanti nella sorgente**

`field_punti_di_interesse` sugli itinerari (155), `field_sentieri_collegati` e
`field_itinerari_correlati` sui POI (224 e 105). Oggi la sorgente legge solo `field_poi_correlati`
(`SardegnaSentieriDatabaseSource.php:110,152`).

- [ ] **Step 4: togli la scrittura del pivot dai job del singolo contenuto**

`syncRelatedPois()` e `syncRelatedPoisForPoi()` smettono di scrivere il pivot e si limitano a
registrare i nid citati; la scrittura passa al job nuovo. Serve perché due `sync()` indipendenti si
cancellano a vicenda.

- [ ] **Step 5: implementa `ResolveSardegnaSentieriRelationsJob`**

Risolve i nid sul database — `properties->>'sardegnasentieri_id'` per le tracce,
`properties->>'out_source_feature_id'` per i POI — scarta le coppie a più di 10 km misurati con
`ST_Distance(t.geometry::geography, p.geometry::geography)` sulla **geometria**, non su un punto
rappresentativo, e scrive con un `sync()` per contenuto.

- [ ] **Step 6: accoda il job nella catena di fine batch**

In `ImportSardegnaSentieriCommand.php:302`, **prima** di `RecalculateSardegnaSentieriLayersJob`.

- [ ] **Step 7: lancia i test e verifica che passino**

- [ ] **Step 8: riavvia Horizon e verifica sui dati veri**

```bash
docker restart horizon-forestas
```
Dopo un import completo: `ec_poi_ec_track` intorno a 1614 righe (1643 coppie meno le 29 oltre i
10 km) e `ec_poi_related_pois` a 637, contro le 240 di oggi.

- [ ] **Step 9: commit** — `feat(oc:8932): i collegamenti fra contenuti si risolvono a fine import`

---

### Task 10: sentieri vicini calcolati dalla geometria

**File:**
- Crea: `database/migrations/<data>_create_ec_track_related_tracks_table.php`
- Crea: `app/Jobs/Import/CalculateNearbyTracksJob.php`
- Crea: `config/sardegnasentieri.php` (chiave `nearby_tracks_radius_meters`, default `50`)
- Modifica: `app/Models/EcTrack.php` (relazione `relatedTracks()`)
- Modifica: `app/Services/Import/SardegnaSentieriImportService.php` (smette di scrivere i sentieri
  correlati di Drupal)
- Modifica: `app/Console/Commands/ImportSardegnaSentieriCommand.php` (catena di fine batch; il
  `--reset` tronca la tabella nuova)
- Test: `tests/Feature/Import/NearbyTracksTest.php`

**Interfacce:**
- Produce: `EcTrack->relatedTracks()` (`BelongsToMany`, tabella `ec_track_related_tracks`, chiavi
  `ec_track_id` e `related_track_id`), modellata su `relatedPois()` di `app/Models/EcPoi.php:43`.

- [ ] **Step 1: scrivi i quattro test che falliscono**

```php
it('collega due percorsi che si toccano', function () {});
it('non collega due percorsi a 2 km di distanza', function () { /* il raggio è in metri, non in gradi */ });
it('lascia senza collegamenti un percorso isolato', function () {});
it('usa il raggio configurato', function () { /* config(['sardegnasentieri.nearby_tracks_radius_meters' => 200]) */ });
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

- [ ] **Step 3: crea la tabella e la relazione**

- [ ] **Step 4: implementa il calcolo**

Un job solo che **itera sulle tracce** e per ciascuna cerca i suoi vicini, scrivendo i risultati in
blocco. **Non** un join a coppie: misurato sulle 761 tracce vere, il join
(`from ec_tracks a join ec_tracks b on b.id > a.id and ST_DWithin(...)`) impiega **289,5 secondi**,
mentre la stessa ricerca su una traccia sola ne impiega **12 ms** — 761 tracce fanno circa 9
secondi. Nel join il pianificatore non sfrutta `ec_tracks_geometry_spatialindex`. Conta anche il
limite: il supervisor della coda ha `timeout => 360`, e 289 s lascia meno del 20% di margine.

**Il cast a `geography` non è facoltativo**: `ST_DWithin(a.geometry::geography,
b.geometry::geography, :radius)`. Senza, il raggio è interpretato in gradi.

Nell'import orario conviene ricalcolare solo le tracce con la geometria cambiata: se allarga troppo
il task, va descritto in `notes.md` invece che implementato.

- [ ] **Step 5: smetti di importare i sentieri correlati di Drupal**

- [ ] **Step 6: accoda il job a fine batch e fai troncare la tabella dal `--reset`**

- [ ] **Step 7: lancia i test e verifica che passino**

- [ ] **Step 8: verifica sui dati veri**

Dopo un import completo: circa **1527 coppie** in `ec_track_related_tracks` con il raggio a 50 m.

- [ ] **Step 9: commit** — `feat(oc:8932): i sentieri correlati si calcolano dalla geometria`

---

### Task 11: dati degli enti

**File:**
- Modifica: `database/migrations/<quella che crea `entes`>` — colonne `pagina_web_title`,
  `drupal_created_at`, `slug`
- Modifica: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` (campo media nuovo,
  galleria, collegamenti, titolo della pagina web, `created`, alias)
- Modifica: `app/Services/Import/SardegnaSentieriImportService.php:1368` (`importOrUpdateEnte`)
- Modifica: `app/Models/Ente.php`, `app/Nova/Ente.php`
- Test: `tests/Feature/Import/EnteImportTest.php`

**Interfacce:**
- Produce: tre colonne nuove su `entes` e i media dell'ente nella media library.

- [ ] **Step 1: scrivi i sei test che falliscono**

```php
it('legge l\'immagine principale dal campo media nuovo', function () { /* 50 enti ce l'hanno solo lì */ });
it('importa la galleria', function () { /* 31 enti */ });
it('importa i collegamenti esterni', function () { /* 10 enti */ });
it('salva il titolo della pagina web', function () {});
it('salva la data di creazione di Drupal', function () { /* Oasi Tepilora: 09/05/2013 */ });
it('ricava lo slug dall\'ultima parte dell\'alias', function () { /* 92 enti, 0 duplicati */ });
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

- [ ] **Step 3: aggiungi le colonne alla `create` di `entes`**

Non una migration nuova: il repo vuole che si modifichi la `create`.

- [ ] **Step 4: leggi i campi nella sorgente e scrivili**

La data di creazione si converte **in PHP** dal timestamp Drupal.

- [ ] **Step 5: mostra le colonne nuove in Nova**

- [ ] **Step 6: lancia i test e verifica che passino**

- [ ] **Step 7: commit** — `feat(oc:8932): immagine, galleria, collegamenti, slug e date degli enti`

---

### Task 12: data di creazione dei punti di interesse

**File:**
- Modifica: `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` (`getPoiDetail`, già
  legge `created` per le tracce)
- Modifica: `app/Dto/Import/ForestasPoiData.php`
- Test: `tests/Feature/Import/PoiCreatedAtTest.php`

**Interfacce:**
- Produce: `properties.forestas.created_at` sui punti di interesse, com'è già sui percorsi.

- [ ] **Step 1: scrivi il test che fallisce**

```php
it('salva la data di creazione del POI', function () { /* «Nuraghe Serbissi»: 02/07/2013 */ });
```

- [ ] **Step 2: lancia il test e verifica che fallisca**

- [ ] **Step 3: porta `created` nel DTO del POI**

Conversione in PHP, mai `FROM_UNIXTIME`.

- [ ] **Step 4: lancia il test e verifica che passi**

- [ ] **Step 5: verifica sui dati veri** — 976 POI su 976 con `properties.forestas.created_at`.

- [ ] **Step 6: commit** — `feat(oc:8932): data di creazione dei punti di interesse`

---

### Task 13: i media si vedono e si gestiscono da Nova

**File:**
- Modifica: `app/Nova/EcTrack.php`, `app/Nova/EcPoi.php`, `app/Nova/Ente.php`
- Test: `tests/Feature/Nova/MediaFieldsTest.php`

**Interfacce:**
- Consuma: le collection `attachments` e `videos` di Task 6.
- Produce: su percorso, punto di interesse ed ente, tre campi distinti per tipo di file, in lettura
  e scrittura.

Oggi nessuna Resource ha un campo media: le foto si trovano solo dalla Resource `Media` filtrando
per modello. I tre campi sono **distinti per tipo**: immagini (collection `default`), documenti
(`attachments`), video (`videos`). Immagine principale e galleria restano nello stesso campo perché
condividono la collection `default` e si distinguono per ordine: separarle richiederebbe di spostare
i media esistenti, che è un lavoro a sé.

**Trappole del repo da rispettare** (`wm-package/.claude/rules/nova.md`):
- sui campi media si usa **`->singleMediaRules()`, mai `->rules()`**: quest'ultimo valida l'intero
  array della collection e blocca **ogni** salvataggio del form, anche senza toccare il campo;
- `Images::make()` impone `['image']` non sovrascrivibile e con Laravel 12 quella regola esclude gli
  SVG: per documenti, video e SVG serve `Files::make()`;
- nel callback `->store()` di un campo `File`, `return null` **distrugge il valore esistente**: si
  torna `true` per non toccarlo.

- [ ] **Step 1: scrivi i cinque test che falliscono**

```php
it('mostra le immagini sulla pagina del sentiero', function () {});
it('mostra i documenti in un campo separato dalle immagini', function () {});
it('mostra i video in un campo separato', function () {});
it('permette di eliminare un documento senza toccare le immagini', function () {});
it('salva il form anche senza toccare i campi media', function () { /* la trappola di ->rules() */ });
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

- [ ] **Step 3: aggiungi i campi alle tre Resource**

`Images::make(__('Images'), 'default')` per le immagini; `Files::make(__('Documents'),
'attachments')` e `Files::make(__('Videos'), 'videos')` per il resto, da
`Ebess\AdvancedNovaMediaLibrary\Fields`, come già si usano in `wm-package/src/Nova/App.php:889`.
I campi vanno nelle Resource di **forestas**, non del package: le due collection sono nostre.

- [ ] **Step 3b: permetti di riusare un file già caricato**

Su Drupal lo stesso file è riferito da più contenuti, e il caso più frequente **non** sono i
documenti ma le foto:

| Collection | File distinti | Collegamenti | Riusati |
|---|---|---|---|
| galleria | 2644 | 3946 | **813** |
| immagine principale | 1498 | 1878 | **221** |
| allegati | 168 | 317 | 26 |
| video | 8 | 15 | 5 |

La media library tiene una copia per contenuto, quindi senza questo la redazione dovrebbe ritrovare
e ricaricare lo stesso file ogni volta. I campi vanno dichiarati con `->enableExistingMedia()`
(`HandlesExistingMediaTrait` di Ebess), che in Nova aggiunge la scelta fra i file già presenti —
**su tutti e tre i campi**: limitarlo a documenti e video coprirebbe 31 casi su 1065.

Serve anche un `config/nova-media-library.php` in forestas con `'enable-existing-media' => true`:
il valore di default del pacchetto è `false`
(`vendor/ebess/advanced-nova-media-library/src/config/nova-media-library.php`) e nel repo non
esiste alcun file che lo sovrascriva.

Il file viene comunque duplicato sul disco: il trait dichiara `// Mimic copy behaviour` e chiama
`copyFromMediaLibrary()`. Risolve la fatica di chi carica, non lo spazio occupato — ed è quello che
serve.

Un test che verifichi che i campi espongano la scelta fra i file esistenti.

- [ ] **Step 4: lancia i test e verifica che passino**

- [ ] **Step 5: verifica a mano in Nova**

Apri il sentiero B 401: le foto si vedono, i documenti stanno in un campo a parte, un documento si
carica e si elimina, e il form si salva anche senza toccare nessun file.

- [ ] **Step 6: commit** — `feat(oc:8932): immagini, documenti e video gestibili da Nova`

---

### Task 14: confronto dei conteggi fra Drupal e lo shard

**File:**
- Modifica: `app/Console/Commands/CompareSardegnaSentieriSourceCommand.php`
- Test: `tests/Feature/Import/CompareSardegnaSentieriSourceCommandTest.php` (esiste)

**Interfacce:**
- Consuma: tutti i task precedenti.
- Produce: il comando confronta anche i dati portati da questo ticket.

- [ ] **Step 1: scrivi i test che falliscono**

```php
it('segnala uno scarto nei collegamenti fra contenuti', function () {});
it('segnala un allegato presente su Drupal e assente nello shard', function () {});
```

- [ ] **Step 2: lancia i test e verifica che falliscano**

- [ ] **Step 3: estendi il comando ai dati nuovi**

Collegamenti fra contenuti, allegati, media degli enti, `related_url`, didascalie.

- [ ] **Step 4: lancia i test e verifica che passino**

- [ ] **Step 5: verifica finale sui dati veri**

Import completo in locale, poi `sardegnasentieri:compare-source`: nessuno scarto sui dati portati da
questo ticket. Infine, in Nova: il sentiero B 401, l'itinerario «Lago di Baratz – Le Prigionette»,
il POI «Nuraghe Serbissi», l'ente «GAL BMG».

- [ ] **Step 6: commit** — `feat(oc:8932): il confronto con la sorgente copre i dati nuovi`
