> Ticket: oc:8959

# Notes — dati di Forestas nel dettaglio della traccia

Secondo pezzo di oc:8959 lato forestas. Il primo (tassonomie, `LABELS`) è nel package:
`wm-package/docs/features/8959-api-del-dettaglio-tracce-e-poi-per-wordpress-e-frontend-forestas/`.
Il formato è descritto in `docs/knowledge/dettaglio-della-traccia-dati-forestas.md`.

## Decisioni (col dev, 2026-11-11)

- **Perimetro**: registro e inclusività dentro `forestas`, allegati e video con l'indirizzo dello
  shard, enti con i ruoli (`entities`; l'API degli enti la fa
  Rubens), sentieri vicini (`nearby_tracks`), link per scaricare (`downloads`), pulizia delle chiavi
  interne e degli id di Drupal (anche `properties.sardegnasentieri_id`). Fuori: dati tecnici
  (`manual_data`/DEM, oc:8641), POI vicini e POI.
- **Crediti delle foto con le foto** (scrum del 2026-11-11): in `feature_image` e `image_gallery`,
  dal package. La prima scelta, `forestas.image_credits`, è superata: vedi in fondo.
- **Forma di `entities`, `nearby_tracks`, `downloads`**: approvata dal dev così com'è «per ora».
- **Roadbook**: resta com'è; diventerà un blocco del dettaglio con l'import, in un pezzo successivo
  (scrum del 2026-11-10).
- **`url`**: resta per ora (pagina Drupal del sentiero); il dev deciderà se toglierlo.
- **In uscita, non nel DB**: il `properties.forestas` salvato resta com'è.
- **Chiavi tutte in inglese**: `info_utili` → `useful_info`, `allegati` → `attachments`, `video` e
  i video caricati → `videos` (stessa forma `{url, title}` degli allegati). L'artifact di analisi
  usava i nomi italiani, ma nessun consumatore li legge ancora.
- **Nessuna modifica al wm-package per i dati di forestas**, su richiesta del dev; i crediti
  delle foto sono nel package perché valgono per tutti gli shard (scrum del 2026-11-11).

## `EC_TRACK_MODEL`

Su locale, dev e UAT era `Wm\WmPackage\Models\EcTrack`: il file su AWS lo scriveva il modello del
package e l'override di forestas non sarebbe stato chiamato (già oggi `geojsonFlatProperties()` non
arrivava nel file). Il default del package è `App\Models\EcTrack`, ed è quello di camminiditalia,
maphub e carg (verificato via SSH, sola lettura). In locale la riga è commentata (copia del `.env` in
scratchpad della sessione). **Su dev e UAT va tolta al deploy**, poi `config:clear` e riavvio di
Horizon.

Conseguenze verificate in locale:
- relazioni già salvate come `App\Models\EcTrack` (media 3.648, termini 3.487, attività 1.824,
  layer 1.574, enti 1.330): nessuna migrazione;
- l'indice riceve `inclusivity_score`/`inclusivity_class` (mapping dinamico);
- **legami traccia-layer**: `LayerableObserver` ora riconosce le tracce (prima il confronto con il
  modello del package era sempre falso) e `PBFGeneratorService::regeneratePbfsForLayer()` trova le
  tracce del layer invece di rigenerare i PBF di tutta l'app. Un detach+attach accoda 1
  `UpdateLayerGeometryJob` (unico) e 1 `GenerateOptimizedPBFChainJob` per legame, non unico:
  salvare in Nova un layer automatico con molte tracce accoda una catena PBF per traccia, come su
  camminiditalia. L'import notturno scrive i legami senza eventi e non è toccato. Rischio noto,
  non corretto qui (sarebbe nel package);
- `$fillable` di forestas al posto di quello del package: nessuno scrive `access_*`/`accessibility_*`
  con assegnazione di massa.

## Deviazioni e altro

- `geojsonFlatProperties()` tolto: i dati sono in `forestas` e nessuno leggeva le chiavi piatte.
  `ForestasColumnsTest` aggiornato.
- `tests/TestCase.php`: `Bus::fake` di `UpdateEcTrackAwsJob`, `UpdateAppConfigJob`,
  `BuildAppPoisGeojsonJob`, `RegenerateTaxonomyWhereOutputsJob` (seguito del bump del package con
  oc:8959: quattro test fallivano scrivendo verso S3).
- GPX e KML di `downloads` rispondono 500 finché oc:8953 (correzione delle rotte) non è in develop;
  GeoJSON risponde 200.
- I titoli di allegati e video sono quelli salvati dall'import in `sardegnasentieri_title`: negli
  esempi locali sono il nome del file.
- Le API di ripiego del package (`/api/ec/track/{id}` senza file, `multiple`) usano la classe del
  package e portano il `forestas` salvato.

## Follow-up

- Togliere `EC_TRACK_MODEL` da `.env` di dev e UAT al deploy; rigenerare file e indice.
- Pezzi successivi: dati tecnici, POI da `pois.geojson`, roadbook come blocchi.
- Decidere se togliere `url` (pagina Drupal del sentiero).

## Review e scrum del 2026-11-11 (13:54)

Review (`wm-review-ticket`) sulla PR #51: due bloccanti, tutti e due visibili solo nel file su AWS.

- **`image_credits` senza id nel file** (`JsonResource::filter()` rinumera gli array con chiavi
  numeriche): superato dalla decisione dello scrum, i crediti escono con le foto dal package
  (branch `feature/oc-8959-crediti-delle-foto` di wm-package). L'import scrive `caption`,
  `author`, `credits` al posto di `sardegnasentieri_caption/_autore/_credits`; il campo Nova delle
  immagini usa `ImageCredits::fields()`. **Niente migration** delle chiavi già salvate (decisione
  del dev): non c'è una produzione e il reset notturno di dev e UAT riscrive le custom properties di
  ogni foto. Fino al primo reset dopo il deploy le foto escono senza crediti, e le chiavi vecchie
  restano nei media senza che nessuno le legga.
- **`nearby_tracks` incompleto dopo l'import**: la catena rigenerava i file dopo il primo blocco
  di `CalculateNearbyTracksJob`. I blocchi si accodavano prima del lavoro e giravano in parallelo,
  quindi nemmeno l'ultimo blocco sapeva se gli altri erano finiti. Ora `CalculateNearbyTracksJob`
  svuota la tabella e accoda i blocchi (`CalculateNearbyTracksChunkJob`) in un `Bus::batch` con
  `allowFailures()`, e nel `finally` accoda `RegenerateTaxonomyWhereOutputsJob`, tolto dalla catena.
- Nuovo test che passa da `EcTrackResource` con la classe di `EC_TRACK_MODEL`, come il file su
  AWS (cleanup della review).

Scrum delle 13:54 (Giuseppe, Rubens):

- **`entities`**: solo `id` e `roles`; il nome lo dà l'API degli enti (Rubens). I ruoli restano
  perché l'API degli enti non li può avere: dipendono dalla traccia (scelta del dev).
- **`nearby_tracks` sono i sentieri correlati**: ogni elemento porta i dati della card del
  carosello nel wireframe (artifact di analisi di Rubens, tavola 3): `route_type`,
  `feature_image`, `distance`, `duration_forward`, oltre a `id`, `ref`, `name`. Tempo e lunghezza
  oggi sono `null` su tutti i sentieri importati (oc:8641).
- **`inclusivity`** e la chiave `class`: niente da fare.
- **POI correlati**: basta l'id, Rubens legge `pois.geojson`.

Cleanup della review non fatti: `EC_TRACK_MODEL` forzato in `AppServiceProvider`, `Route::model`
per l'API di ripiego, il ramo `stdClass` mai raggiunto, `select` ridotto sui correlati, costanti
per le collection nei test, il test duplicato in `ForestasColumnsTest`.

## Secondo ciclo di review (2026-11-11)

- **Batch dei correlati chiuso prima del tempo**: `retry_after` della connessione redis era 90 s,
  più corto del timeout dei job (300 s i blocchi, 360 il supervisor dell'import). Un blocco lento
  (misurato in locale fino a 160 s con 4 blocchi in parallelo) veniva ripreso da un secondo worker;
  `WithoutOverlapping::dontRelease()` scartava la copia, ma Laravel la contava come riuscita
  (`CallQueuedHandler`, `DatabaseBatchRepository::decrementPendingJobs`), e il `finally`
  rigenerava i file prima che il blocco scrivesse. In locale 18 batch avevano `pending_jobs`
  negativo. Il difetto c'era già per tutte le code (ogni supervisor ha un timeout sopra i 90 s):
  `retry_after` portato a 660, sopra il timeout più lungo (600, `geohub-import-supervisor` del
  package). Test: `tests/Feature/Queue/RetryAfterTest.php`. Dev e UAT non impostano
  `REDIS_QUEUE_RETRY_AFTER`: vale il default, dopo il deploy serve il riavvio di Horizon.
- Costo accettato: un job rimasto orfano per un worker morto torna in coda dopo 11 minuti invece
  di 1 minuto e mezzo.
- **Card dei correlati aggiornate su modifica** (richiesta del dev dopo la review): quando in
  Nova cambia un dato della card di una traccia (nome, `ref`, lunghezza/tempo, prima foto e suoi
  crediti, tipo di percorso) o la traccia viene cancellata, `RefreshRelatedTrackCardsJob` (unico
  per traccia, coda `aws`, dopo il commit) riscrive solo i file delle tracce che la mostrano.
  Observer in `app/Observers/`, registrati in `AppServiceProvider`. L'import resta escluso.
  Test: `tests/Feature/EcTrack/RelatedTrackCardsRefreshTest.php`.
- La morphMap del package risolve `App\Models\EcTrack` in `Wm\WmPackage\Models\EcTrack`: un
  controllo `is_a()` sulla classe del termable va fatto contro la classe del package.

## Modifiche dopo il merge (2026-11-12)

- **`entities` diventa `organizations`, raggruppata per ruolo** (richiesta del dev). «Entities» si
  legge «entità», mentre sono enti: comuni, complessi forestali, istituzioni, partner e
  associazioni. `organizations` li copre tutti, «ente governativo» no. I gruppi hanno chiavi in
  inglese (`manager`, `maintainer`, `surveyor`, `operator`, `forest_complex`) e ogni ente porta
  `id` e `name` in tutte le lingue. Cambia la decisione dello scrum delle 13:54, per cui il nome
  lo dava solo l'API degli enti: ora esce anche qui. Un ruolo non previsto esce con il nome
  salvato nel DB. Test in `tests/Feature/EcTrack/TrackDetailForestasTest.php`.
- **Slug degli enti** (richiesta del dev, 2026-11-12): ogni ente di `organizations` porta anche
  `slug`, l'indirizzo della sua scheda nel sito (`/enti/<slug>`); 93 enti su 93 lo hanno.
