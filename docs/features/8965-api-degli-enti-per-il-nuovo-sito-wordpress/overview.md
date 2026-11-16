> Ticket: oc:8965

# API degli enti per il nuovo sito WordPress

## Cosa cambia

Forestas espone gli enti (modello `Ente`, tabella `entes`) al nuovo sito WordPress, che li usa per
l'elenco `/enti`, per la pagina `/enti/<slug>` e per la tab degli enti nella ricerca unica.

- **Un JSON unico con tutti gli enti**, servito da una rotta di forestas protetta da un token
  statico condiviso fra shard e WordPress. Contiene, per ogni ente, tutti i dati della scheda
  Drupal più le tracce e i POI collegati con i loro ruoli. WordPress lo tiene in cache e prende
  l'ente per id o per slug.
- **Una rotta di ricerca degli enti su Elasticsearch**, dietro lo stesso token, che restituisce le
  card complete (id, slug, nome, tipo, immagine) in ordine di rilevanza.
- **Le immagini degli enti hanno le miniature**: `Ente` registra le conversioni di
  `thumbnail_sizes`, come i modelli del package, e l'API le restituisce nella forma di
  `MediaResource`.
- **La descrizione arriva con la sua formattazione**: l'import smette di togliere l'HTML e filtra
  descrizione e contatti con una whitelist (HTMLPurifier). Il `Tiptap` di Nova riceve i pulsanti
  corrispondenti.
- **`Ente::ecPois()`**: la relazione verso i POI collegati, che oggi manca.

Repo coinvolto: solo **forestas**. Il `wm-package` non si tocca.

## Perché

Drupal verrà spento e il nuovo sito WordPress deve mostrare gli enti con gli stessi dati della
scheda Drupal (decisione dell'08/11), leggendoli in modo dinamico dallo shard (27/10, tag 692).
Oggi i 93 enti e le 1.365 righe di `enteables` sono nello shard, ma nessuna API li restituisce:
forestas ha solo `routes/web.php`, `console.php` e `sus.php`.

Le scelte di impostazione vengono dallo scrum dell'11/11 e dal dialogo di pianificazione del 13/11:

- **JSON unico** come per i POI (proposta di Giuseppe, 11/11): con 93 enti che cambiano solo
  all'import, una risorsa che WordPress tiene in cache evita una chiamata allo shard per ogni
  pagina. Stima della dimensione: 1–1,5 MB non compresso (404 KB di testo misurati sul DB locale,
  233 immagini, 1.365 collegamenti).
- **API non pubblica** (Giuseppe, 11/11): nei dati ci sono nomi di persone ed email. Un file su AWS
  come `pois.geojson` sarebbe pubblico (`StorageService::getAppPoisUrl()`), quindi il JSON è una
  rotta dello shard. L'autenticazione è fra due macchine, senza utenti: basta un token statico.
- **Ricerca con Elasticsearch** (Rubens, 11/11): la ricerca di Drupal trova gli enti, e il nuovo
  sito deve coprire quello che Drupal cerca oggi. Se si rivelerà superflua rispetto al filtro sul
  JSON che WordPress ha già, si toglierà.

## Requisiti

### JSON degli enti

- [ ] Rotta `GET api/v1/entes` in forestas, in `routes/api.php`, che `bootstrap/app.php` registra
      con `withRouting(api: …)` (prefisso e gruppo `api`, lo standard di Laravel). Il percorso
      nomina la risorsa e non il client: oggi la usa il sito WordPress, domani potrebbe usarla
      altro.
- [ ] Restituisce un array con tutti gli enti, esclusi quelli marcati
      `properties.forestas.deleted_from_source = true`.
- [ ] Ogni ente ha: `id`, `slug`, `name`, `type` (valore di `TipoEnte`), `description`, `contacts`
      (traduzioni complete, `it` ed `en`), `website` (`url` e `title` da `pagina_web` e
      `pagina_web_title`), `geometry` (GeoJSON `Point` o `null`), `feature_image` e `image_gallery`
      nella forma di `MediaResource` del package (con didascalia, autore e credits), `related_url`,
      `created_at` (`drupal_created_at`), `updated_at` (`properties.forestas.updated_at`).
- [ ] Ogni ente ha `tracks`: tutte le tracce collegate in `enteables`, ciascuna con `id`, `ref`,
      `name`, stato di validazione e l'elenco dei ruoli (`gestore`, `manutentore`, `rilevatore`,
      `operatore`, `complesso_forestale`). WordPress sceglie cosa mostrare, per esempio i
      «Sentieri gestiti» filtrando il ruolo `gestore`.
- [ ] Ogni ente ha `pois`: i POI collegati, ciascuno con `id`, `name` e i ruoli.
- [ ] Tracce e POI marcati `deleted_from_source` non compaiono in `tracks` e `pois`.
- [ ] Il JSON ha un campo `generated_at` con l'ora in cui è stato costruito.
- [ ] La chiave `en` di nome, descrizione e contatti contiene il testo italiano (su Drupal gli enti
      sono solo in italiano): la documentazione dell'API lo dice, senza presentarla come traduzione.
- [ ] La costruzione del JSON non fa una query per ente né per traccia (eager loading di media,
      tracce con il loro stato di validazione, POI).
- [ ] Un test di contratto fissa la forma del JSON, comprese le chiavi delle immagini che vengono da
      `MediaResource` del package.

### Autenticazione

- [ ] Un middleware di forestas accetta la richiesta solo con `Authorization: Bearer <token>` uguale
      al valore configurato, confrontato con `hash_equals`. Senza token o con token errato risponde
      `401`. Stesso schema e stessa semantica di `EnsureExportToken` del package (export per
      Orchestrator, oc:8242), con un segreto proprio.
- [ ] Il token si legge da `config('forestas.wp_api_token')`, alimentato da
      `WP_API_TOKEN` nel `.env`. Con il valore vuoto la rotta risponde sempre `403` (API non
      configurata su questo shard), come `EnsureExportToken`: un token mancante non apre mai l'API, e
      togliere la variabile spegne le rotte senza una release.
- [ ] `WP_API_TOKEN` è documentata in `.env-example` e in `.env-deploy`, senza un valore vero.
- [ ] Il middleware protegge sia il JSON sia la ricerca.
- [ ] Throttle: `throttle:60,1` sul JSON e `throttle:600,1` sulla ricerca. Tutte le chiamate
      arrivano dal server WordPress, quindi il limite vale per l'intero sito: quello della ricerca
      ferma solo un uso anomalo, il limite per visitatore spetta a WordPress.

### Ricerca degli enti

- [ ] `Ente` usa Scout con il driver già configurato (`Matchish\ScoutElasticSearch`), in un indice
      proprio per gli enti.
- [ ] Il documento indicizzato contiene nome, descrizione senza HTML (tutti e due appiattiti dalle
      traduzioni), tipo, slug e i campi della card (URL della miniatura dell'immagine principale).
      Mai i contatti.
- [ ] Il mapping e l'analizzatore dell'indice degli enti (campo `name` per prefissi, edge n-gram)
      si aggiungono in `AppServiceProvider::boot()` come chiave di `elasticsearch.indices`, dopo
      che `WmPackageServiceProvider` ha sostituito quella configurazione con la sua. Un test
      verifica che dopo il boot ci siano sia gli enti sia le tracce.
- [ ] `shouldBeSearchable()` esclude gli enti marcati `deleted_from_source`, con lo stesso scope
      usato dal JSON.
- [ ] Rotta `GET api/v1/entes/search?query=<testo>`, dietro il token, che restituisce le card
      (`id`, `slug`, `name`, `type`, `feature_image`) in ordine di rilevanza.
- [ ] La ricerca non passa il testo dell'utente a `QueryStringQuery` (sintassi Lucene, il default di
      Matchish): costruisce una query `multi_match` su `name` e `description`, con prefissi e una
      tolleranza minima agli errori di battitura. Una ricerca con `(` o `:` risponde 200.
- [ ] Se l'indice degli enti non esiste, la ricerca risponde 503 con un messaggio che lo dice,
      invece di 500.
- [ ] L'import, che salva gli enti con `saveQuietly()`, chiama `searchable()` alla fine di
      `importOrUpdateEnte`, dopo la sincronizzazione delle immagini; `markRemovedEnti` chiama
      `unsearchable()` sugli enti che marca come spariti.

### Immagini

- [ ] `Ente` registra le conversioni di `config('wm-package.services.image.thumbnail_sizes')` (12
      misure) sulla collection `default`, accodate, come `GeometryModel::registerMediaConversions()`.
- [ ] Una procedura documentata genera le miniature delle 233 immagini esistenti
      (`media-library:regenerate` sul modello `Ente`).

### Descrizione e contatti con la loro formattazione

- [ ] Un mutator su `description` e `contatti` di `Ente` filtra ogni scrittura con HTMLPurifier
      (import, Nova, `tinker`), lasciando solo `p`, `br`, `h2`–`h6`, `a[href]`, `em`, `strong`,
      `ul`, `ol`, `li`. Mutator e non evento `saving`, perché l'import salva con `saveQuietly()`.
      La cache di HTMLPurifier (`Cache.SerializerPath`) sta sotto `storage/`, come nel package.
- [ ] L'import non usa più `strip_tags` sulla descrizione (`importOrUpdateEnte`) e non filtra da
      sé: lo fa il mutator.
- [ ] `ezyang/htmlpurifier:^4.19` entra in `composer.json` di forestas come dipendenza diretta
      (oggi arriva solo tramite il `wm-package` e `phpspreadsheet`).
- [ ] Il `Tiptap` di `description` e `contatti` nella Resource Nova `Ente` ha i pulsanti `heading`
      (livelli 2–6), `bold`, `italic`, `link`, `bulletList`, `orderedList`.
- [ ] `sardegnasentieri:import --only=enti` importa solo gli enti, senza accodare POI e sentieri:
      con `--force` serve a riscrivere le descrizioni già importate sul dev, che non ha il reset
      notturno.

### Modello

- [ ] `Ente::ecPois()`: relazione verso `App\Models\EcPoi` attraverso `enteables`, filtrata su
      `enteable_type = Wm\WmPackage\Models\EcPoi` e con il pivot `ruolo`, speculare a
      `EcPoi::enti()`. Il tipo scritto dall'import resta quello di oggi.
- [ ] Scope sul modello per escludere gli enti marcati `deleted_from_source`, usato da JSON e
      ricerca.
- [ ] Slug sempre presente, unico e solo ASCII: l'import passa lo slug di Drupal per `Str::slug()`
      (`comune-di-lodè` → `comune-di-lode`; oggi 4 slug su 93 hanno lettere accentate), lo ricava
      dal nome se manca; in Nova regola `unique` e stessa normalizzazione.

### Messa in opera

- [ ] `docs/howto/deploy-automatico.md`, fra ciò che resta a mano: impostare `WP_API_TOKEN` nel
      `.env` di ogni server prima del deploy (che congela la configurazione) e passarlo a chi
      configura WordPress; lanciare `scout:import "App\Models\Ente"`,
      `media-library:regenerate` per gli enti e `sardegnasentieri:import --only=enti --force`.

### Test

- [ ] Test feature delle due rotte: `401` senza token, con token errato e con token configurato
      vuoto; `200` con il token giusto; forma del JSON (campi, tracce e POI con i ruoli); esclusione
      degli enti marcati come spariti; ricerca che trova un ente per nome.
- [ ] Test dell'import: descrizione con HTML ammesso conservato e HTML non ammesso tolto, contatti
      filtrati, `--only=enti` che non accoda POI e sentieri.

## Rischi

- **Ricerca con sintassi Lucene**: Scout con Matchish passa il testo a `QueryStringQuery`
  (`SearchFactory.php:32-36`), e una parentesi non chiusa fa rispondere 500. Mitigato con una query
  `multi_match` costruita dalla rotta.
- **Slug accentati**: 4 slug su 93 hanno lettere accentate, mentre WordPress costruisce gli URL con
  `sanitize_title()`, che le toglie. Mitigato con slug solo ASCII; i 4 enti cambiano URL rispetto
  a Drupal, e gli eventuali redirect sono lavoro di WordPress.
- **HTML salvato da Nova fuori dalla whitelist**: mitigato spostando il filtro in un mutator del
  modello, che vale per ogni scrittura.
- **Reset notturno su UAT**: dopo il `--reset` delle 06:00 le tracce rinascono con id nuovi e per
  qualche ora `tracks` è vuoto o parziale. Non si risolve qui, perché è del reset e non dell'API:
  la pagina di conoscenza indica a WordPress di tenere il JSON in cache al massimo un'ora, e il
  campo `generated_at` dice di quando è il dato.
- **Indice non allineato**: `saveQuietly()` non fa scattare Scout. Mitigato con `searchable()` dopo
  le immagini e `unsearchable()` sugli enti spariti. L'URL della miniatura di un'immagine nuova
  non risponde finché la coda non genera la conversione: accettato.
- **Mapping cancellato dal package**: `WmPackageServiceProvider.php:607-608` sostituisce
  `elasticsearch.indices`. Mitigato aggiungendo la chiave degli enti in `AppServiceProvider::boot()`.
- **Passi a mano al primo deploy**: senza token configurato l'API risponde 403, senza `scout:import` la ricerca
  non ha indice. Mitigato con la procedura in `docs/howto/deploy-automatico.md` e con il 503
  esplicito della ricerca.
- **Rotazione del token**: un solo segreto condiviso, senza periodo in cui valgono il vecchio e il
  nuovo; ruotarlo comporta qualche minuto di 401. Accettato.

## Out of scope

- **Enti nel dettaglio di tracce e POI**: già coperti da oc:8959, con
  `properties.forestas.organizations` (id, slug e nome per ruolo) in `ForestasTrackDetail` e
  `ForestasPoiDetail`. Tolti da questo ticket il 13/11.
- Le pagine `/enti` e `/enti/<slug>` e la tab degli enti nella ricerca unica su WordPress, compreso
  l'invio del token dal plugin `wp-package`.
- Tempo e lunghezza dei sentieri gestiti: dopo il go-live, con il calcolo in blocco dal DEM
  (oc:8932).
- I servizi degli enti (`field_servizi`): la scheda Drupal non li mostra e oc:8932 li esclude.
- Uniformare `enteable_type` dei POI ad `App\Models\EcPoi`: richiederebbe di cambiare insieme
  import, migrazione ed `EcPoi::enti()`, da cui dipende il dettaglio del POI di oc:8959.

## Moduli toccati

Tutti in **forestas**:

- `bootstrap/app.php` — registrazione di `routes/api.php`
- `app/Providers/AppServiceProvider.php` — mapping dell'indice degli enti
- `routes/api.php` (nuovo) — rotte del JSON e della ricerca
- `app/Http/Middleware/VerifyWpApiToken.php` (nuovo) — token statico del sito WordPress
- `app/Http/Controllers/Api/EnteController.php` (nuovo) — JSON degli enti e ricerca, che costruisce
  anche la card dai documenti dell'indice
- `app/Http/Resources/EnteResource.php` (nuovo) — l'ente nel JSON
- `app/Search/` (nuovo) — `EnteSearch`, `EnteSearchIndex` (mapping), `EnteSearchQuery`
- `app/Support/EnteHtmlSanitizer.php` (nuovo) — whitelist HTML di descrizione e contatti
- `app/Rules/UniqueEnteSlug.php` (nuovo) — slug unico sul valore normalizzato
- `app/Models/Ente.php` — `ecPois()`, scope degli enti attivi, conversioni media, Scout con gli
  errori di Elasticsearch registrati invece che propagati, mutator HTML, `normalizeSlug()`
- `app/Services/Import/SardegnaSentieriImportService.php` — HTML filtrato, aggiornamento dell'indice
- `app/Console/Commands/ImportSardegnaSentieriCommand.php` — `--only=enti`, valori di `--only`
  validati
- `app/Console/Commands/CompareSardegnaSentieriSourceCommand.php` — slug confrontato con la stessa
  normalizzazione dell'import
- `app/Nova/Ente.php` — pulsanti del `Tiptap`, slug normalizzato e unico, ricerca sul database
  (`usesScout()` falso)
- `config/forestas.php` — `wp_api_token`
- `docs/howto/deploy-automatico.md` — passi a mano del primo deploy
- `composer.json` / `composer.lock` — `ezyang/htmlpurifier`
- `.env-example`, `.env-deploy` — `WP_API_TOKEN`
- `tests/Feature/` — test delle rotte e dell'import
- `docs/knowledge/` — pagina di conoscenza sull'API degli enti
