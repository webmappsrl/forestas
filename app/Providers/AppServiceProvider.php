<?php

namespace App\Providers;   

use App\Http\Controllers\Api\Sus\SusTestDecisionController;
use App\Models\EcTrack;
use App\Models\RegistroCatastaleRow;
use App\Observers\RelatedTrackCardObserver;
use App\Observers\RelatedTrackMediaObserver;
use App\Observers\RelatedTrackRouteTypeObserver;
use App\Policies\RegistroCatastaleRowPolicy;
use App\Services\Import\Source\SardegnaSentieriDatabaseSource;
use App\Services\Import\Source\SardegnaSentieriSource;
use App\Services\RegistroCatastale\AnomalyTypes\LinkOrfano;
use App\Services\RegistroCatastale\AnomalyTypes\NumeroDiverso;
use App\Services\RegistroCatastale\AnomalyTypes\RigheMultiple;
use App\Services\RegistroCatastale\AnomalyTypes\RipiegoAmbiguo;
use App\Services\RegistroCatastale\AnomalyTypes\TracciaSenzaCodice;
use App\Services\RegistroCatastale\AnomalyTypes\ValoreNonSanitizzabile;
use App\Services\RegistroCatastale\RegistroAnomalyTypes;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Combined\AllOf;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\TaxonomyTermable;
use Wm\WmPackage\Models\Tile;
use Wm\WmPackage\Policies\PermissionPolicy;
use Wm\WmPackage\Policies\RolePolicy;
use Wm\WmPackage\Policies\TilePolicy;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Da dove l'import di Sardegna Sentieri legge i dati (oc:8900).
        $this->app->bind(SardegnaSentieriSource::class, SardegnaSentieriDatabaseSource::class);

        // oc:8333 — la documentazione vive su /docs/api/sus e non sul default
        // /docs/api: il branch SUS e' una delle integrazioni del Catasto, e il
        // path lascia spazio a documentazioni separate per gli altri partner
        // (Infomont CAI, PDND) senza doverlo rinegoziare con Engineering.
        //
        // Va dichiarato in register(): le route di Scramble sono registrate
        // durante il boot del suo service provider.
        Scramble::configure()->expose(
            ui: 'docs/api/sus',
            document: 'docs/api/sus.json',
        );

        // Tipi di anomalia del registro catastale (oc:8539): il package li
        // unisce ai suoi nella Resource Anomalie. Il nome porta il prefisso
        // della provenienza. Si unisce a quanto gia' in config invece di
        // sovrascrivere, nel caso la chiave abbia gia' altre voci.
        config(['wm-package.features.trail_registry.anomaly_types' => array_merge(
            (array) config('wm-package.features.trail_registry.anomaly_types', []),
            [
                RegistroAnomalyTypes::LINK_ORFANO => LinkOrfano::class,
                RegistroAnomalyTypes::NUMERO_DIVERSO => NumeroDiverso::class,
                RegistroAnomalyTypes::TRACCIA_SENZA_CODICE => TracciaSenzaCodice::class,
                RegistroAnomalyTypes::RIPIEGO_AMBIGUO => RipiegoAmbiguo::class,
                RegistroAnomalyTypes::RIGHE_MULTIPLE => RigheMultiple::class,
                RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE => ValoreNonSanitizzabile::class,
            ],
        )]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Tile::class, TilePolicy::class);
        Gate::policy(RegistroCatastaleRow::class, RegistroCatastaleRowPolicy::class);

        // Card dei sentieri correlati nei file delle tracce (oc:8959): quando cambia una traccia,
        // la sua prima foto o il suo tipo di percorso, si riscrivono i file di chi la mostra.
        EcTrack::observe(RelatedTrackCardObserver::class);
        config('media-library.media_model')::observe(RelatedTrackMediaObserver::class);
        TaxonomyTermable::observe(RelatedTrackRouteTypeObserver::class);

        // Branch API riservato al SUS (oc:8333). Registrato qui e non in
        // routes/api.php perche' bootstrap/app.php non carica quel file:
        // stesso pattern di WmPackageServiceProvider.
        Route::name('sus.')
            ->middleware('api')
            ->prefix('api/v1/sus')
            ->group(base_path('routes/sus.php'));

        // Lo strumento di prova del canale (vedi config/sus.php): fuori da
        // `api/v1/sus`, quindi fuori dalla documentazione e dal gruppo `api`,
        // ma con lo stesso client. Dove `sus.test_tools` e' spento il
        // controller risponde 404.
        Route::name('sus.test.')
            ->middleware(['auth:api', RoleMiddleware::class.':Sus'])
            ->prefix('docs/api/sus/test/trail-applications')
            ->group(function () {
                Route::post('/{application}/approve', [SusTestDecisionController::class, 'approve'])
                    ->whereNumber('application')->name('approve');
                Route::post('/{application}/reject', [SusTestDecisionController::class, 'reject'])
                    ->whereNumber('application')->name('reject');
            });

        // oc:8333 — Scramble di default documenta TUTTE le route sotto /api,
        // incluse quelle registrate da wm-package (mobile app, ec/ugc, wallet,
        // export). La documentazione e' consegnata a un fornitore esterno:
        // esponiamo esclusivamente il branch SUS, che contiene anche i propri
        // endpoint di autenticazione. Stesso approccio di orchestrator (oc:8287).
        Scramble::routes(fn (\Illuminate\Routing\Route $route) => str_starts_with($route->uri(), 'api/v1/sus/'));

        // oc:8333 — Scramble riconosce da solo `auth:sanctum`, non il guard
        // `api` di tymon/jwt-auth usato qui: lo schema di sicurezza va
        // dichiarato a mano, altrimenti la documentazione non direbbe a
        // Engineering come autenticare le chiamate.
        //
        // Lo schema viene registrato fra i components e applicato alle sole
        // operazioni del branch SUS: `$openApi->secure()` lo renderebbe
        // globale, marcando come protetti anche login e refresh, che sono il
        // modo per *ottenere* il token.
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            // Nessun server dichiarato: i path del documento sono completi
            // (`/api/v1/sus/...`, vedi `api_path` in config/scramble.php),
            // quindi le chiamate partono dall'host da cui la documentazione e'
            // aperta. Dichiarare un URL assoluto derivato da APP_URL farebbe
            // partire il "Try it" verso un altro origin quando la pagina viene
            // aperta da un host diverso (127.0.0.1 invece di localhost, il
            // dominio di UAT invece di quello locale), e il browser lo
            // bloccherebbe per CORS.
            $openApi->servers = [];

            $openApi->components->addSecurityScheme(
                'jwt',
                SecurityScheme::http('bearer', 'JWT')
                    ->setDescription(
                        'Token ottenuto da `POST /api/auth/login`. Va inviato su ogni chiamata '
                        .'al branch SUS come `Authorization: Bearer <token>`.'
                    )
            );

            // Stoplight Elements, il renderer della pagina, mostra i campi di un
            // corpo `multipart/form-data` il cui schema e' un `allOf`, ma non li
            // raccoglie quando costruisce il FormData: il "Try it" parte con un
            // corpo vuoto e la chiamata risponde 422 dicendo che mancano anche i
            // campi appena compilati.
            //
            // Scramble produce quell'`allOf` quando un endpoint ha sia una Form
            // Request (da cui ricava `$ref`) sia gli attributi `#[BodyParameter]`
            // che descrivono i campi uno per uno (da cui ricava un secondo
            // oggetto). I due blocchi hanno le stesse proprieta' e gli stessi
            // campi obbligatori: il secondo in piu' porta descrizioni ed esempi,
            // quindi tenere solo quello non toglie niente al documento e
            // restituisce uno schema che il renderer sa compilare.
            //
            // Si tocca solo il caso previsto — un unico oggetto con proprieta'
            // dentro l'`allOf` — e si lascia stare tutto il resto.
            foreach ($openApi->paths as $path) {
                foreach ($path->operations as $operation) {
                    foreach ($operation->requestBodyObject->content ?? [] as $schema) {
                        if (! $schema->type instanceof AllOf) {
                            continue;
                        }

                        $objects = array_values(array_filter(
                            $schema->type->items,
                            fn ($item) => $item instanceof ObjectType && $item->properties !== [],
                        ));

                        if (count($objects) === 1) {
                            $schema->type = $objects[0];
                        }
                    }
                }
            }

            // I campi a scelta mostrano accanto a ogni valore la sua etichetta:
            // nel payload viaggia solo il valore (`applicant`), ma chi integra
            // deve ritrovarlo fra le voci del format («Il richiedente»), che e'
            // il documento su cui si e' discusso. Le etichette vengono dallo
            // schema dei campi, le stesse che Nova mostra all'operatore, cosi'
            // documentazione e interfaccia non possono divergere.
            //
            // Gira dopo l'appiattimento dell'`allOf` qui sopra: prima, le
            // proprieta' del corpo non sono ancora raggiungibili come oggetto.
            $applicationSchema = app(ApplicationSchema::class);

            foreach ($openApi->paths as $path) {
                foreach ($path->operations as $operation) {
                    foreach ($operation->requestBodyObject->content ?? [] as $schema) {
                        if (! $schema->type instanceof ObjectType) {
                            continue;
                        }

                        foreach ($schema->type->properties as $name => $property) {
                            $values = $applicationSchema->valuesDescription((string) $name);

                            if ($values !== null) {
                                $property->setDescription(trim($property->description.' '.$values));
                            }
                        }
                    }
                }
            }

            // Le risposte che non vengono dai controller ma dai middleware del
            // canale: 401 da `auth:api`, 403 da `role:Sus`, 429 da `throttle`.
            // Scramble non le vede, o le vede senza dire nulla, e chi integra le
            // incontra comunque. Si ricavano dai middleware che ogni rotta ha
            // davvero, cosi' quando una rotta cambia la documentazione la segue.
            // I corpi sono quelli che Laravel e Spatie producono senza debug.
            $channelRoutes = collect(Route::getRoutes()->getRoutes())
                ->filter(fn (\Illuminate\Routing\Route $route) => str_starts_with($route->uri(), 'api/v1/sus/'));

            $errorBody = fn (string $message) => Schema::fromType(
                (new ObjectType)
                    ->addProperty('error', (new StringType)->examples([$message]))
                    ->setRequired(['error'])
            );

            $messageBody = fn (string $message) => Schema::fromType(
                (new ObjectType)
                    ->addProperty('message', (new StringType)->examples([$message]))
                    ->setRequired(['message'])
            );

            // Una risposta gia' descritta da un controller vince su questa,
            // che e' generica: il 401 del refresh, per esempio, spiega anche la
            // finestra di rinnovo.
            $documented = fn ($operation, int $code) => collect($operation->responses)->contains(
                fn ($response) => $response instanceof Response
                    && (string) $response->code === (string) $code
                    && $response->description !== ''
            );

            // Senza un nome, il limitatore conta per utente su tutte le rotte
            // che ne hanno uno: il 429 di ciascuna deve dire quali altre
            // consumano lo stesso conteggio.
            $sharedLimit = $channelRoutes
                ->filter(fn (\Illuminate\Routing\Route $route) => collect($route->gatherMiddleware())
                    ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))
                    && collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth')))
                ->flatMap(fn (\Illuminate\Routing\Route $route) => collect($route->methods())
                    ->reject(fn (string $method) => $method === 'HEAD')
                    ->map(fn (string $method) => sprintf('`%s %s`', $method, substr($route->uri(), strlen('api/v1/sus')))))
                ->implode(', ');

            foreach ($openApi->paths as $path) {
                foreach ($path->operations as $operation) {
                    $route = $channelRoutes->first(fn (\Illuminate\Routing\Route $route) => $route->uri() === ltrim($path->path, '/')
                        && in_array(strtoupper($operation->method), $route->methods(), true));

                    if ($route === null) {
                        continue;
                    }

                    // Il 404 di una domanda lo riscrive questo shard
                    // (`bootstrap/app.php`): il package ne dichiara la
                    // descrizione, il corpo e' quello che si risponde qui.
                    foreach ($operation->responses as $response) {
                        if ($response instanceof Response && (string) $response->code === '404') {
                            $response->setContent('application/json', $errorBody(__('Application not found.')));
                        }
                    }

                    $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));
                    $authenticated = $middleware->contains(fn (string $m) => str_starts_with($m, 'auth'));

                    if ($authenticated && ! $documented($operation, 401)) {
                        $operation->addResponse(Response::make(401)
                            ->setDescription('Token assente, scaduto o revocato: si rifà il login per ottenerne uno nuovo. Il messaggio è lo stesso su ogni indirizzo del canale, che la domanda chiesta esista o no.')
                            ->setContent('application/json', $errorBody(__('Access denied: a valid token of the channel client is required.'))));
                    }

                    if ($middleware->contains(fn (string $m) => str_starts_with($m, RoleMiddleware::class) || str_starts_with($m, 'role:'))
                        && ! $documented($operation, 403)) {
                        $operation->addResponse(Response::make(403)
                            ->setDescription('Il token è valido ma appartiene a un utente che non è il client del canale: il canale è riservato al client dello sportello. La risposta è la stessa su ogni indirizzo del canale, che la domanda chiesta esista o no.')
                            ->setContent('application/json', $errorBody(__('Access denied: a valid token of the channel client is required.'))));
                    }

                    $throttle = $middleware->first(fn (string $m) => str_starts_with($m, 'throttle:'));

                    if ($throttle !== null) {
                        [$max, $minutes] = array_pad(explode(',', substr($throttle, strlen('throttle:'))), 2, '1');
                        $counted = $authenticated
                            ? 'tutte le chiamate fatte con lo stesso utente a '.$sharedLimit
                            : 'le chiamate dallo stesso indirizzo IP';

                        $operation->addResponse(Response::make(429)
                            ->setDescription(sprintf(
                                'Superato il limite di %d chiamate %s: il conteggio comprende %s. `Retry-After` dice quanti secondi attendere prima di riprovare.',
                                (int) $max,
                                (int) $minutes === 1 ? 'al minuto' : sprintf('ogni %d minuti', (int) $minutes),
                                $counted,
                            ))
                            ->setContent('application/json', $messageBody('Too Many Attempts.'))
                            ->addHeader('Retry-After', new Header(
                                description: 'Secondi da attendere prima di riprovare.',
                                schema: Schema::fromType(new IntegerType),
                            )));
                    }
                }
            }

            // Il token si ottiene con /auth/login, che non puo' richiederlo:
            // la sicurezza va applicata a tutto il branch tranne quell'endpoint.
            foreach ($openApi->paths as $path) {
                if (str_ends_with($path->path, '/auth/login')) {
                    continue;
                }

                foreach ($path->operations as $operation) {
                    $operation->addSecurity(new SecurityRequirement(['jwt' => []]));
                }
            }
        });
    }
}
