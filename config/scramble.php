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
        'description' => <<<'MARKDOWN'
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
        4. `GET {BASE_URL}/trail-applications/{id}` — legge a che punto e' la domanda, i dati tecnici
           e, a domanda approvata, il numero del sentiero.

        `{BASE_URL}` e' `https://<dominio>/api/v1/sus`, dove il dominio e' quello su cui questa
        documentazione e' pubblicata.

        **Le chiamate che modificano una domanda rispondono solo com'e' andata l'azione; per leggerla
        c'e' una sola chiamata, la 4.** Non cambia nulla e si puo' ripetere quante volte si vuole: e'
        quella da usare per seguire una domanda nel tempo. Il numero del sentiero compare li', e solo
        a domanda approvata: durante l'istruttoria puo' ancora cambiare.

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
        da quelle di esercizio. L'URL di base e' quello del dominio su cui questa documentazione e'
        pubblicata.

        ### Il collaudo si azzera ogni notte

        **Ogni giorno alle 06:00, ora italiana, il database del collaudo viene svuotato e ricaricato
        da capo, e le domande create vengono cancellate.** Una domanda creata il pomeriggio la
        mattina dopo non esiste piu': le chiamate sul suo `id` rispondono `404`. Una prova va quindi
        svolta per intero — creazione, allegati, conferma — nella stessa giornata.
        MARKDOWN,
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
