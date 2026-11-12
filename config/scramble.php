<?php

return [
    /*
     * Which routes to document. String or array form; use Scramble::routes() for custom selection.
     *
     * 'api_path' => [
     *     'include' => 'api',
     *     'exclude' => ['api/internal'],
     * ],
     *
     * Without *, patterns match path segments (api matches api and api/users, not apiary).
     * With *, Str::is is used (e.g. api/v*).
     *
     * One static include → default server is /{include} and paths are stripped (/users).
     * Multiple includes or wildcards → server defaults to / and paths stay full (/api/users).
     * Override with `servers`, or use Scramble::registerApi() for separate bases.
     */
    // Wildcard invece di 'api': con un include statico Scramble sposta il
    // prefisso nel server e lascia i path senza `/api`. Con il wildcard i path
    // restano completi, cosi' il documento e' leggibile anche senza il
    // riquadro del server (oc:8333).
    'api_path' => 'api/*',

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => null,

    /*
     * The path where your OpenAPI specification will be exported.
     */
    'export_path' => 'api.json',

    /*
     * Cache configuration for the generated OpenAPI document.
     *
     * Use `scramble:cache` to warm the cache and `scramble:clear` to invalidate it.
     */
    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        /*
         * API version.
         */
        'version' => env('API_VERSION', '1.0.0'),

        /*
         * Description rendered on the home page of the API documentation (`/docs/api`).
         */
        // {APP_URL} diventa l'indirizzo dell'ambiente su cui gira la pagina (locale, dev, UAT).
        'description' => str_replace('{APP_URL}', rtrim((string) env('APP_URL', ''), '/'), <<<'MARKDOWN'
        API di integrazione tra il **Catasto Sentieri** di Forestas e lo **Sportello Unico Sentieri** (SUS)
        della Regione Sardegna.

        ## A cosa serve

        Il SUS e' lo sportello pubblico dove viene presentata l'istanza; il Catasto Sentieri e' il
        proprietario del dato. Queste API sostituiscono il passaggio manuale delle informazioni fra i due
        sistemi, che oggi richiederebbe di reinserire a mano ogni istanza raccolta dal SUS e, all'inverso,
        ogni comunicazione di manutenzione.

        Sono previsti due procedimenti:

        - **Procedura 02 — proposta di nuovo sentiero.** L'istante carica la traccia GPX e i dati
          sul SUS. Prima della conclusione dell'istruttoria e' prevista una fase di **pre-validazione**,
          in cui il Catasto restituisce una scheda del sentiero — anche cartografica — utile a chi
          propone, a chi istruisce e a chi valuta. In quella fase il Catasto **assegna o blocca un
          numero di sentiero provvisorio**, che diventa definitivo solo a istruttoria conclusa.
        - **Procedura 05 — comunicazione di manutenzione.** Il SUS identifica il soggetto manutentore
          e lo collega ai sentieri di sua competenza.

        Il Catasto riceve aggiornamenti anche da variazioni d'ufficio, indipendenti dalle istanze SUS.

        ## Cosa c'e' oggi

        Oltre agli endpoint di autenticazione e di verifica della connessione, il canale ha le
        chiamate con cui il SUS porta nel Catasto una domanda di accatastamento (procedura 02) e ne
        segue l'esito:

        1. `POST {BASE_URL}/trail-applications` — crea la domanda con i dati del modulo e il file del
           tracciato, e restituisce l'`id` e i dati tecnici calcolati dal tracciato;
        2. `POST {BASE_URL}/trail-applications/{id}/attachments` — carica un allegato, uno per chiamata;
        3. `POST {BASE_URL}/trail-applications/{id}/confirm` — dichiara conclusa la compilazione e
           trasmette la domanda all'istruttoria;
        4. `GET {BASE_URL}/trail-applications/{id}` — legge la domanda: i dati dichiarati come il
           Catasto li ha registrati, gli allegati caricati, a che punto e', i dati tecnici e, a domanda
           approvata, il numero del sentiero.

        `{BASE_URL}` e' `{APP_URL}/api/v1/sus`.

        **Le chiamate che modificano una domanda rispondono solo com'e' andata l'azione; per leggerla
        c'e' una sola chiamata, la 4.** Non cambia nulla e si puo' ripetere quante volte si vuole: e'
        quella da usare per seguire una domanda nel tempo. Il numero del sentiero compare li', e solo
        a domanda approvata: durante l'istruttoria puo' ancora cambiare.

        ## Come avviene la comunicazione, passo per passo

        Il SUS chiama e il Catasto risponde: il Catasto non chiama mai il SUS. Per sapere come
        procede una domanda dopo la trasmissione, il SUS la rilegge con la chiamata 4.

        ```mermaid
        sequenceDiagram
            autonumber
            participant SUS as SUS (portale della Regione)
            participant API as Catasto Sentieri (queste API)
            participant FOR as Istruttore Forestas

            SUS->>API: POST /auth/login con le credenziali del client
            API-->>SUS: 200 access_token, valido 60 minuti

            Note over SUS,API: Fase 1 - creazione della domanda
            SUS->>API: POST /trail-applications con i dati del modulo e il file del tracciato
            alt modulo valido e tracciato leggibile
                API-->>SUS: 201 id, dem_data, dem_status
                Note right of API: la domanda nasce in compilazione (under_compilation)<br/>con il numero del sentiero già riservato, non mostrato
            else modulo non valido o tracciato illeggibile
                API-->>SUS: 422 errori campo per campo, nessuna domanda creata
            end

            Note over SUS,API: Fase 2 - allegati, uno per chiamata
            loop per ogni documento e fotografia
                SUS->>API: POST /trail-applications/id/attachments
                API-->>SUS: 201 collection e count
            end

            Note over SUS,API: Fase 3 - trasmissione
            SUS->>API: POST /trail-applications/id/confirm
            alt fascicolo completo
                API-->>SUS: 200 status under_review, already_confirmed false
                Note right of API: da qui la domanda non accetta più allegati
            else manca qualcosa
                API-->>SUS: 422 error e missing (campi e allegati mancanti)
                Note right of API: la domanda resta in compilazione:<br/>si completa e si richiama confirm
            end

            Note over API,FOR: Fase 4 - istruttoria, fuori da queste API
            FOR->>API: approva o respinge la domanda

            Note over SUS,API: Fase 5 - lettura dello stato, ripetibile
            loop finché serve seguire la domanda
                SUS->>API: GET /trail-applications/id
                API-->>SUS: 200 submitted_data, attachments, status, dem_data, code solo se approvata, rejection_reason solo se respinta
            end
        ```

        | Passo | Il SUS chiede | Il Catasto risponde |
        |---|---|---|
        | Accesso | `POST /auth/login` | `200` con il token; alla scadenza si rinnova con `POST /auth/refresh` |
        | 1. Creazione | `POST /trail-applications` con i dati del modulo e il file del tracciato | `201` con `id`, `dem_data` e `dem_status`. Se il calcolo dei dati tecnici non e' ancora pronto, `dem_status` e' `processing` e i dati si leggono dopo con la chiamata 4. Con un modulo non valido, `422` con gli errori campo per campo, e la domanda non viene creata |
        | 2. Allegati | `POST /trail-applications/{id}/attachments`, un file per chiamata | `201` con la `collection` e quanti file contiene. Un documento caricato di nuovo sostituisce il precedente. Dopo la trasmissione, `409` |
        | 3. Trasmissione | `POST /trail-applications/{id}/confirm` | `200` con `status` `under_review`. Se manca qualcosa, `422` con `missing`, e la domanda resta in compilazione. Ripetuta su una domanda gia' trasmessa risponde di nuovo `200`, con `already_confirmed` a `true` |
        | 4. Istruttoria | — | L'istruttore Forestas approva o respinge la domanda nel Catasto: non passa da queste API |
        | 5. Lettura | `GET /trail-applications/{id}`, quante volte serve | `200` con i dati dichiarati come il Catasto li ha registrati (`submitted_data`), quanti allegati ci sono per `collection` (`attachments`), lo `status`, i dati tecnici, il numero del sentiero (`code`) solo a domanda approvata e il motivo (`rejection_reason`) solo a domanda respinta |

        ## Gli strumenti di prova

        Questa pagina permette di provare il canale per intero senza scrivere codice. In basso a
        sinistra c'e' il pulsante **Strumenti di prova**: aprendolo compare un riquadro con i passi di
        una domanda, nell'ordine in cui il SUS li fa.

        Prima di tutto si fa il login: da **Auth → Login**, nel pannello di prova della chiamata, con
        le credenziali del client. Il token restituito viene usato da solo da tutti i pulsanti e da
        tutte le prove di questa pagina; la riga in alto a destra dice se e' attivo.

        1. **Prova una domanda completa** — crea una domanda con valori di prova e il tracciato di
           prova (chiamata 1). L'`id` restituito finisce da solo nel campo «id della domanda».
        2. **Scarica il tracciato di prova (GPX)** — e' il file usato al passo 1: cade in un settore del
           catasto, quindi la creazione lo accetta. Serve anche per le vostre prove interne.
        3. **Carica gli allegati obbligatori** — carica un file di prova per ciascuno dei quattro
           allegati che servono a trasmettere: relazione, tabella dati, una fotografia, piano di
           manutenzione (chiamata 2, ripetuta quattro volte).
        4. **Trasmetti** — chiude la compilazione (chiamata 3). Senza il passo 3 risponde `422` con
           l'elenco di cio' che manca: e' un buon modo per vedere quell'errore.

        I passi 1, 3 e 4 sono chiamate vere di queste API, le stesse descritte qui sotto: quello che
        fanno i pulsanti si puo' rifare con il proprio codice. In ogni momento la chiamata 4, dal suo
        pannello di prova, mostra la domanda: i dati dichiarati, gli allegati, lo stato.

        MARKDOWN.((bool) env('SUS_TEST_TOOLS_ENABLED', false) ? <<<'MARKDOWN'
        ### Approvare o respingere una domanda per prova

        Su questo ambiente il riquadro ha anche **Approva** e **Respingi**, con il campo del motivo.
        Decidono una domanda trasmessa (`under_review`) come farebbe l'istruttore Forestas, cosi' si
        possono provare da soli tutti gli esiti della chiamata 4 — il numero del sentiero a domanda
        approvata, il motivo a domanda respinta — e il `409` di una conferma fatta dopo la decisione.
        **Non fanno parte di queste API** e non esistono in esercizio.

        MARKDOWN : '').<<<'MARKDOWN'
        ## Gli allegati

        Si caricano con la chiamata 2, uno per chiamata, indicando in `collection` dove va il file.
        La traccia non e' fra questi: viaggia con la creazione, nel campo `geometry`.

        | Campo del format | `collection` | Formati ammessi dal format | Serve per trasmettere |
        |---|---|---|---|
        | 67 — Relazione descrittiva | `report` | solo PDF | si' |
        | 68 — Traccia GPS | — (si manda con la creazione, in `geometry`). Per le prove: [tracciato-di-prova.gpx]({APP_URL}/docs/sus/tracciato-di-prova.gpx) | GPX o GeoJSON | si' |
        | 69 — Tabella dati sentiero | `trail_data_sheet` | solo ZIP | si' |
        | 70 — Documentazione fotografica | `photos` | immagini, da 1 a 5 | si', almeno una |
        | 71 — Eventuali autorizzazioni | `authorizations` | PDF oppure ZIP | no |
        | 72 — Piano di manutenzione | `maintenance_plan` | PDF oppure ZIP | si' |

        Il limite del format e' di 5 MB per file. Un documento caricato di nuovo nella stessa
        `collection` sostituisce il precedente; le fotografie invece si sommano, fino a cinque, e una
        fotografia identica a una gia' caricata non viene contata due volte.

        **I formati e il limite di 5 MB il Catasto oggi non li controlla ancora**: accetta qualunque
        file. Vanno rispettati comunque, perche' il controllo verra' aggiunto e da quel momento un file
        fuori formato sara' rifiutato.

        ## I campi che diventano obbligatori

        Alcuni campi sono obbligatori solo in base al valore di un altro. Se mancano, la creazione
        risponde `422` indicando il campo:

        | Se... | ...allora sono obbligatori |
        |---|---|
        | `soggetto_gestore` (campo 47) e' `third_party` | `soggetto_gestore_other` (48) |
        | `soggetto_rilevatore` (campo 49) e' `other_surveyor` | `soggetto_rilevatore_other` (50) e `soggetto_rilevatore_email` (51) |
        | `soggetto_manutentore` (campo 53) e' `other_legal_entity` | `soggetto_manutentore_other` (54) e `soggetto_manutentore_cf` (55) |
        | `soggetto_custode_presente` (campo 57) e' `true` | `soggetto_custode` (58) |

        ## Un esempio completo

        Le stesse chiamate del diagramma, con `curl`. I valori sono di prova; `{BASE_URL}` e' quello
        indicato sopra. Il tracciato e' [tracciato-di-prova.gpx]({APP_URL}/docs/sus/tracciato-di-prova.gpx):
        cade in un settore del catasto, quindi la creazione lo accetta, mentre un tracciato fuori da
        ogni settore riceve `422`. Va bene anche per le vostre prove interne. Le richieste con file vanno in `multipart/form-data` (l'opzione `-F` di
        `curl`): i campi strutturati, come `activities` e `difficulty`, viaggiano come stringa JSON.

        ```bash
        BASE_URL={APP_URL}/api/v1/sus

        # Accesso: il token vale 60 minuti
        TOKEN=$(curl -s -X POST "$BASE_URL/auth/login" -H 'Accept: application/json' \
          -F email=<email del client> -F password=<password del client> | jq -r .access_token)

        # 1. Creazione: risponde 201 con id, dem_data e dem_status
        curl -X POST "$BASE_URL/trail-applications" \
          -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
          -F name='Sentiero del Monte Arcosu' \
          -F from='Case Marganai' -F to='Punta Perda Liana' \
          -F distance=12.5 -F ascent=640 \
          -F duration_forward=240 -F duration_backward=200 \
          -F activities='["hiking","mtb"]' \
          -F difficulty='{"hiking":"EE"}' \
          -F existing_signage=good_condition \
          -F proposed_managing_municipalities='["Domusnovas"]' \
          -F municipality_email=protocollo@comune.example.it \
          -F soggetto_gestore=forestas -F soggetto_rilevatore=applicant \
          -F soggetto_manutentore=forestas -F soggetto_custode_presente=false \
          -F last_survey_date=2026-10-20 -F maintenance_frequency_months=12 \
          -F geometry=@tracciato-di-prova.gpx

        # 2. Allegati: uno per chiamata, ciascuno risponde 201
        curl -X POST "$BASE_URL/trail-applications/42/attachments" \
          -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
          -F collection=report -F file=@relazione.pdf
        curl -X POST "$BASE_URL/trail-applications/42/attachments" \
          -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
          -F collection=trail_data_sheet -F file=@tabella-dati.zip
        curl -X POST "$BASE_URL/trail-applications/42/attachments" \
          -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
          -F collection=photos -F file=@foto-1.jpg
        curl -X POST "$BASE_URL/trail-applications/42/attachments" \
          -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
          -F collection=maintenance_plan -F file=@piano-manutenzione.pdf

        # 3. Trasmissione: 200 se completa, 422 con l'elenco di cio' che manca
        curl -X POST "$BASE_URL/trail-applications/42/confirm" \
          -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'

        # 5. Lettura: si puo' ripetere quante volte serve
        curl "$BASE_URL/trail-applications/42" \
          -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
        ```

        `42` sta per l'`id` restituito dalla creazione. Gli stessi passi si fanno dai pulsanti in alto
        a destra in questa pagina: «Prova una domanda completa» esegue la creazione con questi valori e
        mette l'`id` nel campo sotto, «Carica gli allegati obbligatori» carica un file di prova per
        ciascuno dei quattro allegati che servono a trasmettere, «Trasmetti» chiude la compilazione.

        **La specifica completa in formato OpenAPI** e' qui: [{APP_URL}/docs/api/sus.json]({APP_URL}/docs/api/sus.json). Si importa
        in Postman o Insomnia per avere tutte le chiamate pronte, oppure si usa per generare il codice
        del client.

        ### Le percorribilita' hanno nomi diversi da quelli del format

        Il campo 36 del format elenca tre percorribilita' — «trek», «MTB», «Ippo» — ma in questa API
        si scrivono con gli identificatori che il Catasto usa per le attivita': rispettivamente
        `trekking`, `mtb` e `horse`. Il campo `activities` ne accetta altri quattro, che il Catasto
        gestisce e il format non prevede: `hiking`, `accessible-trails`, `nordic-walking`,
        `educational-trails`. Un valore fuori da questi sette e' rifiutato con `422`.

                **La creazione non si corregge, si rifa.** Non esiste un endpoint che modifichi i dati di una
        domanda gia' creata: e' una scelta, descritta nella pagina della chiamata.

        ## Il format

        I campi della domanda sono quelli del format concordato al tavolo tecnico, il foglio
        «Format istanza accatastamento V» del file
        [SUS-EVO_ANALOGHI_FORES_02_Format_Istanza accatastamento_VALIDATA_221026](https://drive.google.com/file/d/1xlzZ8ND3gmLGRjKWXsIjmaLE51semeGI/view).
        Nella documentazione ogni parametro riporta il suo numero di campo, nella forma «campo 29 del
        format — *Origine (da)*»: il numero e' quello della colonna «N. campo».

        **Le unita' non sono quelle del format.** Il format chiede la lunghezza in metri e i tempi di
        percorrenza nel formato `ORE:MINUTI`; nel payload di questa API la lunghezza (`distance`)
        viaggia in **chilometri con un decimale** e i tempi (`duration_forward`, `duration_backward`)
        in **minuti interi**.

        ## Come si accede

        L'accesso e' riservato al client SUS, che riceve credenziali dedicate. Con quelle ottiene un
        token (`POST /api/v1/sus/auth/login`) e lo invia su ogni chiamata successiva nell'header
        `Authorization: Bearer <token>`.

        Il token dura `expires_in` secondi (60 minuti). Alla scadenza si rinnova con
        `POST /api/v1/sus/auth/refresh`, entro 14 giorni dall'emissione; oltre quel termine va rifatto il
        login.

        Il client puo' usare **esclusivamente** gli endpoint documentati qui: ogni altra route della
        piattaforma risponde `403`. Il login e' limitato a 100 tentativi al minuto per indirizzo IP.

        ## Contatti

        Per segnalazioni tecniche sull'integrazione: **Webmapp**, supporto tecnico del Catasto
        Sentieri — `info@webmapp.it`. Le credenziali del client non vanno mai inviate su questo
        canale.

        ## Ambienti

        Le prove di connessione si effettuano sull'ambiente di collaudo (UAT), con credenziali distinte
        da quelle di esercizio. L'URL di base e' `{APP_URL}/api/v1/sus`.

        ### Il collaudo si azzera ogni notte

        **Ogni giorno alle 06:00, ora italiana, il database del collaudo viene svuotato e ricaricato
        da capo, e le domande create vengono cancellate.** Una domanda creata il pomeriggio la
        mattina dopo non esiste piu': le chiamate sul suo `id` rispondono `404`. Una prova va quindi
        svolta per intero — creazione, allegati, conferma — nella stessa giornata.
        MARKDOWN),
    ],

    'ui' => [
        'title' => 'Catasto Sentieri — API SUS',
    ],

    /*
     * Load Scramble's development tools on documentation pages. An explicit
     * SCRAMBLE_DEV_TOOLS value takes precedence over APP_DEBUG.
     */
    'dev_tools' => [
        'enabled' => env('SCRAMBLE_DEV_TOOLS', env('APP_DEBUG', false)),
    ],

    'renderer' => 'elements',

    'renderers' => [
        /*
         * Stoplight Elements config options: https://docs.stoplight.io/docs/elements/b074dc47b2826-elements-configuration-options
         */
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        /*
         * Scalar API reference config options: https://scalar.com/products/api-references/configuration
         */
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => 'https://proxy.scalar.com',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'include',
        ],
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    'servers' => null,

    /**
     * Determines how Scramble stores the descriptions of enum cases.
     * Available options:
     * - 'description' – Case descriptions are stored as the enum schema's description using table formatting.
     * - 'extension' – Case descriptions are stored in the `x-enumDescriptions` enum schema extension.
     *
     *    @see https://redocly.com/docs-legacy/api-reference-docs/specification-extensions/x-enum-descriptions
     * - false - Case descriptions are ignored.
     */
    'enum_cases_description_strategy' => 'description',

    /**
     * Determines how Scramble stores the names of enum cases.
     * Available options:
     * - 'names' – Case names are stored in the `x-enumNames` enum schema extension.
     * - 'varnames' - Case names are stored in the `x-enum-varnames` enum schema extension.
     * - false - Case names are not stored.
     */
    'enum_cases_names_strategy' => false,

    /**
     * When Scramble encounters deep objects in query parameters, it flattens the parameters so the generated
     * OpenAPI document correctly describes the API. Flattening deep query parameters is relevant until
     * OpenAPI 3.2 is released and query string structure can be described properly.
     *
     * For example, this nested validation rule describes the object with `bar` property:
     * `['foo.bar' => ['required', 'int']]`.
     *
     * When `flatten_deep_query_parameters` is `true`, Scramble will document the parameter like so:
     * `{"name":"foo[bar]", "schema":{"type":"int"}, "required":true}`.
     *
     * When `flatten_deep_query_parameters` is `false`, Scramble will document the parameter like so:
     *  `{"name":"foo", "schema": {"type":"object", "properties":{"bar":{"type": "int"}}, "required": ["bar"]}, "required":true}`.
     */
    'flatten_deep_query_parameters' => true,

    'middleware' => [
        // RestrictedDocsAccess rimosso (oc:8333): fuori da `local` bloccherebbe
        // l'accesso alla documentazione, che deve essere raggiungibile da
        // Engineering su UAT. La pagina espone solo la struttura delle API:
        // gli endpoint restano protetti dal token. Stessa scelta di orchestrator.
        'web',
    ],

    'extensions' => [],

    /*
     * Automatically document API security (OpenAPI `security` / `securitySchemes`) based on route
     * middleware.
     *
     * Disabled by default. Uncomment the line below to enable `MiddlewareAuthSecurityStrategy`.
     * When at least one documented route uses middleware matching the configured patterns (by default
     * `auth` and `auth:*`), bearer auth is applied globally. Routes without matching middleware are
     * marked as public (`security: []`).
     *
     * Set to `null` explicitly to disable. If you already configure security manually via
     * `afterOpenApiGenerated` / `extendOpenApi`, keep this disabled to avoid duplicate schemes.
     *
     * Customize with a class-string or [class, options]:
     *
     * 'security_strategy' => [
     *     \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
     *     [
     *         'middleware' => ['auth', 'auth:*'],
     *         'scheme' => \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer'),
     *     ],
     * ],
     */
    // 'security_strategy' => \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
    'security_strategy' => null,
];
