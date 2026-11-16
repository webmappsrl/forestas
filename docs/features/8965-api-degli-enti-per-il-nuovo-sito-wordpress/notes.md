> Ticket: oc:8965

# Notes — API degli enti per il nuovo sito WordPress

## Divergenze dal piano, task per task

### Task 3: nomi e registrazione delle rotte

Il piano metteva le rotte sotto `api/v1/website`, registrate in `AppServiceProvider` come quelle del
SUS. Il 16/11 il dev ha chiesto di cambiare due cose:

- **Il percorso nomina la risorsa, non il client**: `api/v1/entes` ed `api/v1/entes/search`, perché
  in futuro potrebbe usarle anche altro oltre al sito WordPress. Di conseguenza `VerifyWpApiToken`,
  `WP_API_TOKEN` / `forestas.wp_api_token`, `Api\EnteController`, `EnteResource` in
  `app/Http/Resources/`, test in `tests/Feature/Entes/`. La rotta `ping` è stata tolta: i test del
  token girano sulle rotte vere. Nel SUS il nome del client resta nel percorso (`api/v1/sus`)
  perché lì è la regola di accesso (`RestrictSusClient`).
- **`routes/api.php`, lo standard di Laravel**, registrato in `bootstrap/app.php` con
  `withRouting(api: …)`, invece di un gruppo in `AppServiceProvider`. Quel gruppo veniva dal modo in
  cui il package registra le sue rotte, giusto per un package ma non per il progetto. SUS e
  dettaglio del POI restano in `AppServiceProvider`: spostarli è fuori dal ticket, e il secondo deve
  stare dopo il package che sostituisce.

### Task 5: indice senza settings propri

Il piano dichiarava anche `EnteSearchIndex::settings()`. Non serve: Matchish ripiega su
`elasticsearch.indices.settings.default` del package, che ha già `edge_ngram_analyzer`. Resta solo
il mapping.

### Task 6: `--only=enti` con `--reset` rifiutato

Non era nel piano. `--reset` tronca tracce e POI qualunque sia `--only`: con `--only=enti` li
cancellerebbe senza reimportarli. Il comando rifiuta la combinazione prima del backup e del reset.

## Bug trovati

- **`toSearchableArray()` falliva con un ente senza descrizione** (`strip_tags(null)`): trovato
  nella prova a mano su Elasticsearch locale (Task 5), corretto con un test.
- **Dalla review finale del branch** (16/11), corretti ciascuno con un test che falliva prima:
  - il JSON caricava geometrie e `properties` intere delle tracce collegate (circa 17 MB su 1.330
    tracce): ora seleziona solo `id`, `name` e `ref`;
  - un `tipo_ente` fuori da `TipoEnte`, che l'import salva apposta quando un termine Drupal non è
    mappato, mandava in 500 tutto il JSON e faceva fallire l'indicizzazione: ora si usa il valore
    grezzo (`Ente::tipoEnteValue()`);
  - nella descrizione indicizzata le parole di blocchi diversi si incollavano (`Il GALTesto`);
  - un Tiptap svuotato salvava `<p></p>` (e `<p>&nbsp;</p>`) invece di `null`.

## Decisioni

- **Review con `wm-review-ticket`** (16/11), sul diff non committato. Corretti, ciascuno con un test:
  - **la ricerca di Nova sugli enti passava per Elasticsearch** (bloccante): con il trait
    `Searchable` sul modello, Nova attiva Scout da sé. Ora `App\Nova\Ente::usesScout()` è falso,
    come in `AbstractEcResource` del package;
  - errori di Elasticsearch registrati invece che propagati (`Ente::searchable()` /
    `unsearchable()`), come `TaxonomyTermableObserver` del package;
  - slug unico sul valore normalizzato (`UniqueEnteSlug`) e normalizzazione in un punto solo
    (`Ente::normalizeSlug()`), usata anche da `sardegnasentieri:compare-source`;
  - valori di `--only` validati: un refuso con `--reset` non tronca più tracce e POI.

- **Stima saltata** (13/11): il dev ha chiesto di non fare la stima; su Orchestrator non è stato
  scritto nulla. La proposta di `wm-estimate` era «Misurato: 1,9h + Stimato: 7,2h = Totale: 9,1h».
- **Tag del ticket saltati** (13/11), su richiesta del dev.
- **Token allineato a `EnsureExportToken`** (16/11): dopo il confronto con il login JWT stile SUS,
  il dev ha scelto il token statico. `VerifyWpApiToken` copia lo schema del middleware dell'export
  per Orchestrator del package (oc:8242): 403 se il token non è configurato, 401 se manca o è errato.
- **Token rinominato `WP_API_TOKEN`** (16/11): il percorso nomina la risorsa (`api/v1/entes`), il
  token nomina chi lo possiede, come in `EnsureExportToken` («ogni consumer usa un token proprio»).
  `VerifyWpApiToken` va su ogni rotta che il sito chiamerà; un altro server avrà il suo token.
  Scartato `WP_PACKAGE_TOKEN`: gli enti sono solo di forestas.
- **Throttle** (16/11): `throttle:60,1` sul JSON, `throttle:600,1` sulla ricerca. Tutto il sito
  WordPress passa da un solo client, quindi il limite vale per l'intero sito.
- **`composer.lock`** (Task 2): `composer require` alzava HTMLPurifier a 4.19.1 e riscriveva la
  voce di `wm/wm-package`. Il lock è stato ripristinato e aggiornato a mano solo nel
  `content-hash`: HTMLPurifier resta 4.19.0, già installato tramite il package.
- **Link relativi tolti dall'HTML degli enti** (16/11): HTMLPurifier filtra gli schemi ma lascia
  passare gli `href` relativi (`/node/123`), che su WordPress punterebbero a pagine sbagliate.
  `EnteHtmlSanitizer` toglie il tag e tiene il testo, come `DiaryTextService` di camminiditalia
  (oc:8169).

## Follow-up

- **Creare gli indici di Elasticsearch nel deploy, per tracce ed enti**: oggi `ec_tracks` ed
  `entes` si creano con `scout:import` a mano, una volta per ambiente, e nessuno script di deploy lo
  fa. Il codice scrive nell'indice senza controllare che esista (`searchable()` nell'import e nei
  job del package): su un ambiente nuovo, un import prima di `scout:import` fa creare a
  Elasticsearch un indice vero con quel nome (`auto_create_index` è attivo), con il mapping
  dedotto dai dati, e l'alias di `scout:import` non si crea più. Per oc:8965 si è scelto di
  documentare l'ordine dei passi e il rimedio in `docs/howto/deploy-automatico.md` (review finale,
  16/11); una soluzione unica nel deploy varrebbe per tutti e due gli indici.

- **Le rotte degli enti non sono pronte per un'app**: il token statico va bene fra server, ma dentro
  un'app distribuita si estrae dal pacchetto, e l'API tornerebbe di fatto pubblica. Se un giorno la
  usasse l'app, serve un'altra autenticazione (per esempio il JWT degli utenti dell'app) accanto al
  token dei server. Gli URL non cambiano.

- **Factory di HTMLPurifier riusabile nel `wm-package`**: forestas (`EnteHtmlSanitizer`, oc:8965)
  e camminiditalia (`DiaryTextService`, oc:8169) configurano HTMLPurifier con la stessa logica
  (whitelist, cache sotto `storage/`, link relativi tolti). camminiditalia riusa
  `HasEmbeddableRichText::buildRichTextPurifier()` chiamandolo direttamente sul trait, cosa
  deprecata da PHP 8.1 («Calling static trait method … is deprecated»). Una classe pubblica nel
  package, fuori dal trait, eliminerebbe la duplicazione e la deprecazione.
