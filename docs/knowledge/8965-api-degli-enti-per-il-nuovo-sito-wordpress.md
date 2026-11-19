# API degli enti

Come il sito WordPress legge gli enti dallo shard: rotte, token, forma del JSON, ricerca.

## Come funziona oggi

### Rotte e token

- `GET api/v1/entes`: tutti gli enti in un JSON unico. `GET api/v1/entes/search?query=<testo>`: la
  ricerca degli enti per la ricerca unica del sito. Stanno in `routes/api.php`, che
  `bootstrap/app.php` registra con `withRouting(api: …)`; il percorso nomina la risorsa, non il
  client.
- Entrambe sono dietro `VerifyWpApiToken`: header `Authorization: Bearer <token>` confrontato con
  `config('forestas.wp_api_token')` (variabile `WP_API_TOKEN`). Token non configurato → 403, token
  mancante o sbagliato → 401: la stessa semantica di `EnsureExportToken` del package (export per
  Orchestrator, oc:8242). Il token appartiene al sito WordPress e va su ogni rotta che il sito
  chiamerà; un altro server avrà il suo.
- Throttle: 60 chiamate al minuto sul JSON, 600 sulla ricerca. Tutto il sito passa da un solo
  client, quindi il limite vale per l'intero sito; il limite per visitatore spetta a WordPress.
- I passi del primo deploy (token prima del deploy, `scout:import` subito dopo, miniature, reimport
  degli enti) sono in [deploy-automatico.md](../howto/deploy-automatico.md).

### Il JSON degli enti

- `{"generated_at": …, "data": [...]}`, con gli enti non marcati
  `properties.forestas.deleted_from_source` (`Ente::active()`); circa 1,8 MB per 93 enti.
- Ogni ente: `id`, `slug`, `name`, `type`, `description`, `contacts`, `website` (`url`, `title`),
  `geometry` (`Point` 2D), `feature_image` e `image_gallery` nella forma di `MediaResource` del
  package (prima foto per `order_column` = immagine principale), `related_url`, `created_at`,
  `updated_at`, `tracks` (`id`, `ref`, `name`, `validation_status`, `roles`, `created_at` di
  Drupal), `pois` (`id`, `name`, `roles`). Tracce e POI spariti da Drupal sono esclusi.
- **Le tracce con ruolo `gestore` hanno anche i campi della card** del carosello «Sentieri
  gestiti» del sito (oc:8990): `route_type`, `feature_image`, `distance`, `duration_forward`, gli
  stessi di `ForestasDetailParts::trackCard()` per i sentieri correlati. Lunghezza e tempo restano
  `null` finché non gira il DEM. Il sito ordina il carosello per `created_at`, come Drupal.
- Delle tracce la query legge una `properties` ridotta (`jsonb_build_object` in `EnteController`):
  solo le chiavi che servono a card e `created_at`, mai geometria e `properties` intere. La forma è fissata da
  `tests/Feature/Entes/EnteIndexTest.php`.
- **La chiave `en` di nome, descrizione e contatti contiene il testo italiano**: su Drupal gli enti
  sono solo in italiano, l'import lo copia anche lì. Non è una traduzione.
- **Campi vuoti in forma diversa**: `description`, `contacts` e `related_url` senza valore escono
  come `[]`, non come oggetto; `created_at` è ISO 8601, `updated_at` è il `changed` di Drupal
  (`Y-m-d H:i:s`, senza fuso).
- `type` è il valore grezzo salvato dall'import (`Ente::tipoEnteValue()`): può essere fuori da
  `TipoEnte` quando un termine Drupal non è mappato.
- **Il sito deve tenere il JSON in cache al massimo un'ora.** Su UAT il reset notturno delle 06:00
  ricrea tracce e POI con id nuovi, e per qualche ora i collegamenti sono parziali: con una cache
  breve il sito si riallinea da solo. `generated_at` dice di quando è il dato.

### La ricerca

- `Ente` usa Scout con l'indice `entes` (`EnteSearchIndex`). Il documento ha nome e descrizione in
  testo semplice, tipo, slug e i campi della card; mai i contatti.
- La query è un `multi_match` su `name^3`, `name.edge^2`, `description` (`EnteSearchQuery`): il
  testo dell'utente è un valore, non sintassi Lucene. La risposta sono le card (`id`, `slug`,
  `name` con le traduzioni, `type`, `feature_image`); indice assente → 503.
- Il mapping si aggiunge in `AppServiceProvider::boot()`, perché
  `WmPackageServiceProvider::packageRegistered()` sostituisce `elasticsearch.indices` con quella del
  package; i settings sono quelli `default` del package, che hanno già `edge_ngram_analyzer`.
- L'import aggiorna l'indice da sé (trappola in `.claude/rules/import-sardegna-sentieri.md`).
  `Ente::searchable()` e `unsearchable()` registrano gli errori di Elasticsearch invece di
  propagarli, come `TaxonomyTermableObserver` del package.
- `App\Nova\Ente::usesScout()` è falso: la ricerca di Nova sugli enti resta sul database.

### Dati degli enti

- Descrizione e contatti passano da `EnteHtmlSanitizer` (mutator del modello, quindi anche da Nova):
  restano `p`, `br`, `h2`–`h6`, `a[href]` con `http`, `https`, `mailto`, `tel`, `em`, `strong`,
  `ul`, `ol`, `li`; i link relativi perdono il tag; un campo senza testo visibile diventa `null`.
- Lo slug è l'URL della pagina dell'ente sul sito: solo ASCII, dal nome se manca
  (`Ente::normalizeSlug()`), unico sul valore normalizzato in Nova (`UniqueEnteSlug`).
- Le immagini degli enti hanno le 12 conversioni di `thumbnail_sizes`, registrate su `Ente`.
- `sardegnasentieri:import --only=enti --force` riscrive solo gli enti; `--only=enti` con
  `--reset` è rifiutato, perché il reset tronca tracce e POI qualunque sia `--only`.

## Perché così

- **Un JSON unico invece di rotte separate** (oc:8965): 93 enti che cambiano solo all'import; il
  sito li tiene in cache e prende l'ente per id o slug. Proposta di Giuseppe allo scrum dell'11/11.
- **Rotta autenticata e non un file su AWS** (oc:8965): nei dati ci sono nomi ed email, e
  l'API non deve essere pubblica (Giuseppe, 11/11); un file come `pois.geojson` sarebbe pubblico.
- **Token statico e non login JWT** (oc:8965): fra due server, senza utenti, un segreto condiviso
  ha meno parti in movimento, da tutte e due le parti. Il package ha già fatto la stessa scelta per
  l'export di Orchestrator.
- **Ricerca su Elasticsearch** (oc:8965): la ricerca di Drupal trova gli enti e il sito deve
  coprire ciò che Drupal cerca oggi (Rubens, 11/11). Se si rivelasse superflua rispetto al filtro
  sul JSON già in cache, si toglie.
- **Card complete nella ricerca, non solo gli id** (oc:8965): la card esce già dal documento
  indicizzato e funziona anche se il sito non ha il JSON in cache.
- **Enti nel dettaglio di tracce e POI non qui** (oc:8965): li dà già
  `properties.forestas.organizations` di oc:8959 (id, slug, nome per ruolo).
- **`enteable_type` dei POI lasciato al tipo del package** (oc:8965): `EcPoi::enti()`, da cui
  dipende il dettaglio del POI di oc:8959, filtra su quel tipo; `Ente::ecPois()` ne è lo specchio.

## Come ci siamo arrivati

- **Rotte separate per elenco, `ids`, dettaglio e ricerca** (analisi dell'11/11, superata): più
  chiamate dal sito, più rotte da mantenere, senza vantaggi con 93 enti in cache.
- **Login JWT con un utente macchina, come il SUS** (oc:8965, scartato): servivano login e refresh
  nel plugin WordPress, un ruolo, e un middleware che confinasse il client al suo prefisso (il
  segreto JWT è lo stesso per tutta la piattaforma). Più vicino allo standard OAuth, ma il beneficio
  con un solo client è piccolo: in WordPress resterebbe comunque una password che dura quanto il
  token.
- **Prefisso `api/v1/website` e token `ENTES_API_TOKEN`** (oc:8965, superati): il percorso nominava
  il client e il token la risorsa; il dev ha chiesto il contrario, perché le rotte potranno servire
  anche ad altri client e il token deve essere di chi lo possiede.
- **Ricerca filtrando il JSON su WordPress** (oc:8965, scartata per ora): avrebbe evitato l'indice,
  ma contraddiceva la richiesta di Rubens; resta l'alternativa se l'indice non servisse.
