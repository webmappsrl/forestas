> Ticket: oc:8959

# Notes — Distanza dei percorsi vicini al POI configurabile

## Divergenze dal piano, task per task

### Task 2: il blocco di calcolo

Il piano diceva: raggio letto in SQL con `COALESCE(NULLIF(…, 0), 1000)` e `WithoutOverlapping` con
`releaseAfter(360)` al posto di `dontRelease()`. Il codice finale è diverso, in due passi.

1. Review finale dell'esecuzione: due ricalcoli sovrapposti (un cambio in Nova durante l'import)
   fanno blocchi dai confini diversi, con chiavi di `WithoutOverlapping` diverse, che girano insieme
   sugli stessi POI. In READ COMMITTED la DELETE del secondo non vedeva le righe appena scritte dal
   primo, e `ec_poi_ec_track` non ha un vincolo unico: righe doppie. Aggiunto il lock delle righe
   dei POI (`lockForUpdate`, in ordine di id) e spostati calcolo, cancellazione e scrittura nella
   stessa transazione.
2. `wm-review-ticket` del 16/11: con il lock `WithoutOverlapping` è ridondante, e `releaseAfter`
   consumava i tentativi di Horizon anche per i blocchi dell'import, facendo slittare la
   rigenerazione di fine import. Tolto il middleware: il lock basta a mettere in fila. Il raggio
   non si legge più in SQL ma con `PoiNearbyTracksDistance::forApp()` dopo il lock, per ogni app dei
   POI del blocco, e si passa alla query come binding: una regola sola per job e dettaglio, il log
   riporta il raggio davvero usato, nessuna query in più per il log.

Il test `ritenta un blocco…` del piano è stato sostituito da `blocca i POI del blocco prima di
riscriverli…`, `non scarta un blocco…` e `usa per ogni POI il raggio della sua App…`.

### Task 3: il job che ricalcola solo i POI

Il piano lo chiamava `CalculatePoiNearbyTracksJob`, con `chunks()`. Rinominato
`RecalculatePoiTracksForDistanceJob` (review del 16/11: si confondeva con `CalculateNearbyTracksJob`
e `CalculatePoiTracksChunkJob`) e reso `ShouldBeUniqueUntilProcessing`: più salvataggi ravvicinati
accodano un ricalcolo solo. La costruzione dei blocchi è passata a
`CalculatePoiTracksChunkJob::forImportApp()`, che usa anche il batch dell'import: prima l'import
dipendeva dal job nato per Nova.

### Task 4: l'observer

In più rispetto al piano: il test verifica `afterCommit` (`$job->afterCommit === true`) e l'unicità
del job. Il test dichiara un `Bus::fake` completo (il job più i job del package che il salvataggio
dell'App accoda) e i dischi `s3`/`wmfe` finti: il `Bus::fake` di un test sostituisce quello di
`tests/TestCase.php`, e l'observer del package sull'App scrive su S3.

### Task 5: campo Nova e dettaglio

- Il campo ha anche `->default(1000)`, così il form di creazione non parte con un campo `required`
  vuoto.
- Review del 16/11: il campo compare solo sull'App dell'import (`canSee`), e la chiave
  `nearby_tracks_max_distance_meters` esce solo nel dettaglio dei POI dell'App dell'import. È l'unica
  i cui POI sono collegati per vicinanza: altrove il valore non avrebbe effetto. Test nuovi `non
  mostra la distanza sulle altre App` e `non espone la distanza per i POI di un'altra App…`; questi
  due sono stati scritti dopo la modifica del codice, non visti fallire prima.
- Le due asserzioni sulle chiavi esatte di `PoiDetailForestasTest` includono la chiave nuova.

### Task 6: documentazione e verifica

Aggiornata anche `docs/knowledge/dettaglio-della-traccia-dati-forestas.md`, che diceva ancora «POI
entro 1 km… a fine import». Esiti delle verifiche: vedi «Verifiche».

## Bug trovati

- **Righe doppie in `ec_poi_ec_track` con due ricalcoli sovrapposti**: vedi la divergenza del task 2.

## Decisioni

- **Nuovo merge di oc:8959 in `develop`** (16/11): il dev ha scelto di lavorare sotto lo stesso
  ticket, sapendo che è il quarto merge. Il ticket su Orchestrator resta com'è (stato `released`,
  nessuna stima scritta): richiesta del dev.
- **Raggio nel dettaglio del POI e non in `config.json`** (16/11): il dev non vuole toccare il
  package, e `AppConfigService` non ha un punto di estensione per lo shard. Allo scrum del 16/11
  Giuseppe aveva indicato il JSON di configurazione: da riallineare con lui se il raggio servirà
  fuori dal dettaglio del POI.
- **Colonna di `apps`, non chiave di `properties`** (16/11): scelta del dev, con nome esplicito.
- **Nessun massimo al raggio** (16/11, challenge): scelta del dev; il campo resta `required`.
- **Finestra di disallineamento accettata** (16/11, challenge): fino alla fine del batch un POI può
  mostrare la distanza nuova con i percorsi vecchi.
- **Ricalcolo dei soli POI al cambio della distanza** (16/11, challenge): al posto di
  `CalculateNearbyTracksJob` così com'era, scelto prima della challenge.
- **Cleanup della review applicati tutti** (16/11, richiesta del dev): vedi le divergenze dei task
  2-6.
- **Stima saltata** su richiesta del dev. Quella di `wm-estimate` era Misurato 1,63h + Stimato 2,5h
  = 4,13h, non scritta su Orchestrator.
- **Tag:** nessun tag associato; il ticket ha già `forestas` e `wm-package`.

## Verifiche

- Suite completa dopo i cleanup della review: 694 passati, 9 saltati (leggono la copia di Drupal).
- Prova in locale dopo i cleanup: due salvataggi a pochi secondi (2000, poi 2500). Il primo
  ricalcolo era già partito, quindi ne sono girati due (l'unicità vale finché il job non parte); tutti
  i blocchi del secondo con 2500 m, nessuna riga doppia in `ec_poi_ec_track`, sentieri correlati
  intatti (3.110). Rimessa a 1000: 4.283 righe.
- PHPStan: nessun errore sui file toccati; restano 6 errori preesistenti in `tests/Feature/Sus/*`
  (`JWTAuth::fromUser()`), fuori da questo lavoro.
- Pint: passato sui file toccati.
- Prova in locale (prima dei cleanup della review): distanza dell'App 1 a 2000 dal modello, batch
  `sardegnasentieri poi nearby tracks` con 20 blocchi, nessuno fallito; `ec_poi_ec_track` da 4.283 a
  6.588 righe; `ec_track_related_tracks` intatta (3.110); `api/ec/poi/274` con
  `nearby_tracks_max_distance_meters: 2000` e 8 percorsi. Rimessa a 1000: di nuovo 4.283 righe.

## Follow-up

- Senza un massimo, un valore oltre 2.147.483.647 (limite di `integer`) dà un errore SQL invece di
  un errore di validazione.
- Con raggi molto grandi il lock tiene ferme più a lungo le righe dei POI del blocco.
- **`related_pois` dei POI → `nearby_pois`?** Allo scrum del 16/11 Giuseppe ha chiesto di non
  chiamarli «related» se calcolati per vicinanza; oggi sono i collegamenti di Drupal. Il dev chiede
  chiarimenti a Giuseppe.
- **WordPress:** la pagina del POI deve leggere `forestas.nearby_tracks_max_distance_meters` al posto
  del «5 km» fisso, e convertire i metri in km (1500 → «1,5 km»). Il commento in
  `wp-forestas/themes/forestas-child/wp-package/poi.php:85` cita ancora
  `sardegnasentieri.poi_tracks_radius_meters` e «il raggio non si scrive». Da segnalare a Rubens.
- **Al deploy:** `php artisan migrate`, riavvio di Horizon. Il valore resta 1000 finché nessuno lo
  cambia: nessun ricalcolo da lanciare.
