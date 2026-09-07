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

        ## Stato attuale

        Questa versione contiene **solo l'infrastruttura di accesso**: un endpoint di verifica della
        connessione, piu' gli endpoint di autenticazione. Gli endpoint di preistruttoria e di
        accatastamento definitivo saranno pubblicati qui, sullo stesso prefisso e con la stessa
        autenticazione, quando il set minimo di dati sara' stato concordato fra le parti.

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
