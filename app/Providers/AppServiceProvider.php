<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\Tile;
use Wm\WmPackage\Policies\PermissionPolicy;
use Wm\WmPackage\Policies\RolePolicy;
use Wm\WmPackage\Policies\TilePolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(Tile::class, TilePolicy::class);

        // Branch API riservato al SUS (oc:8333). Registrato qui e non in
        // routes/api.php perche' bootstrap/app.php non carica quel file:
        // stesso pattern di WmPackageServiceProvider.
        Route::name('sus.')
            ->middleware('api')
            ->prefix('api/v1/sus')
            ->group(base_path('routes/sus.php'));

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
