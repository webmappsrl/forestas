<?php

namespace App\Providers;

use App\Models\User;
use App\Nova\App as NovaApp;
use App\Nova\Dashboards\Main;
use App\Nova\EcPoi;
use App\Nova\EcTrack;
use App\Nova\Ente;
use App\Nova\FeatureCollection;
use App\Nova\Layer;
use App\Nova\Media as NovaMedia;
use App\Nova\TaxonomyActivity;
use App\Nova\TaxonomyPoiType;
use App\Nova\TaxonomyTheme as NovaTaxonomyTheme;
use App\Nova\TaxonomyWarning;
use App\Nova\TaxonomyWhere;
use App\Nova\Tile as NovaTile;
use App\Nova\UgcPoi;
use App\Nova\UgcTrack;
use App\Nova\User as NovaUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Features;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Menu\MenuGroup;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaApplicationServiceProvider;

class NovaServiceProvider extends NovaApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        Nova::style('nova-custom', resource_path('css/nova.css'));

        $this->getFooter();

        Nova::mainMenu(function (Request $request) {
            return [
                MenuSection::dashboard(Main::class)->icon('chart-bar'),

                // Catasto Sentieri (oc:8489). Dichiarata qui, e vuota, solo
                // per fissarne la POSIZIONE: le voci le aggiunge il package
                // (WmPackageServiceProvider::injectMenuSectionItems()) e solo
                // a dominio acceso. Senza questa riga la sezione esisterebbe
                // lo stesso, ma in fondo al menu.
                MenuSection::make(__('Catasto'), [])
                    ->icon('map')
                    ->collapsedByDefault(),

                MenuSection::make(__('Admin'), [
                    MenuItem::resource(NovaApp::class)
                        ->canSee(fn(Request $request) => $request->user()->hasRole('Administrator')),
                    MenuItem::resource(NovaUser::class)
                        ->canSee(fn(Request $request) => $request->user()->hasRole('Administrator')),
                    MenuItem::resource(NovaMedia::class)
                        ->canSee(fn(Request $request) => $request->user()->hasRole('Administrator')),
                    MenuItem::resource(NovaTile::class)
                        ->canSee(fn(Request $request) => $request->user()->hasRole('Administrator')),
                ])->icon('user')
                    ->canSee(fn(Request $request) => $request->user()->hasRole('Administrator'))
                    ->collapsedByDefault(),

                MenuSection::make('UGC', [
                    MenuItem::resource(UgcPoi::class),
                    MenuItem::resource(UgcTrack::class),
                ])->icon('document')
                    ->collapsedByDefault(),

                MenuSection::make('EC', [
                    MenuItem::resource(EcPoi::class),
                    MenuItem::resource(EcTrack::class),
                    MenuItem::resource(Ente::class),
                    MenuItem::resource(Layer::class),
                    MenuItem::resource(FeatureCollection::class),
                ])->icon('document')
                    ->collapsedByDefault(),

                MenuSection::make('Taxonomies', [
                    MenuItem::resource(TaxonomyPoiType::class),
                    MenuItem::resource(TaxonomyActivity::class),
                    MenuItem::resource(NovaTaxonomyTheme::class),
                    MenuItem::resource(TaxonomyWhere::class),
                    MenuItem::resource(TaxonomyWarning::class),
                ])->icon('document')
                    ->collapsedByDefault(),

                MenuSection::make(__('Files'), [
                    MenuItem::externalLink(__('Icons'), route('icons.upload.show'))->openInNewTab(),
                ])->icon('folder')
                    ->canSee(fn(Request $request) => $request->user()->hasRole('Administrator'))
                    ->collapsedByDefault(),

                // Sezione Tools: wm-package cerca una MenuSection con questo nome
                // e vi appende Horizon, Minio, Kibana e i comandi DB
                // (WmPackageServiceProvider:519-556). Se non la trova la crea lui
                // con icona 'briefcase' — dichiararla qui permette di aggiungere
                // voci di progetto, mantenendo lo stesso aspetto.
                //
                // Attenzione: il package ricostruisce la sezione per
                // accodarvi le proprie voci, e nel farlo conserva icona,
                // richiudibilita' e stato iniziale ma NON canSee(): la
                // visibilita' va impostata sul singolo MenuItem, non sulla
                // sezione.
                MenuSection::make(__('Tools'), [
                    // Documentazione delle API SUS generata da Scribe (oc:8333),
                    // consegnata a Engineering per l'integrazione con il SUS.
                    MenuItem::externalLink(__('SUS API documentation'), url('/docs/api/sus'))
                        ->openInNewTab()
                        ->canSee(fn (Request $request) => $request->user()->hasRole('Administrator')),
                ])->icon('briefcase')
                    ->collapsedByDefault(),
            ];
        });
    }

    /**
     * Register the configurations for Laravel Fortify.
     */
    protected function fortify(): void
    {
        Nova::fortify()
            ->features([
                Features::updatePasswords(),
                // Features::emailVerification(),
                // Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
            ])
            ->register();
    }

    /**
     * Register the Nova routes.
     */
    protected function routes(): void
    {
        Nova::routes()
            ->withAuthenticationRoutes(default: true)
            ->withPasswordResetRoutes()
            ->withoutEmailVerificationRoutes()
            ->register();
    }

    /**
     * Register the Nova gate.
     *
     * This gate determines who can access Nova in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewNova', function (User $user) {
            // Il client SUS (oc:8333) e' un canale programmatico verso un ente
            // esterno: non deve accedere al backoffice. Il gate resta una
            // blacklist di ruoli — portarlo a can('access-nova') escluderebbe
            // Validator e Contributor, che oggi passano.
            return ! $user->hasAnyRole(['Guest', 'Sus']);
        });
    }

    /**
     * Get the dashboards that should be listed in the Nova sidebar.
     *
     * @return array<int, \Laravel\Nova\Dashboard>
     */
    protected function dashboards(): array
    {
        return [
            new \App\Nova\Dashboards\Main,
        ];
    }

    /**
     * Get the tools that should be listed in the Nova sidebar.
     *
     * @return array<int, \Laravel\Nova\Tool>
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();

        //
    }

    private function getFooter()
    {
        Nova::footer(function () {
            return Blade::render('nova/footer');
        });
    }
}
