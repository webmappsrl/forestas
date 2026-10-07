<?php

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * La sezione del Catasto la dichiara forestas (NovaServiceProvider, per
 * fissarne la posizione) e la riempie il package, che la ritrova confrontando
 * l'etichetta tradotta con __('Trail registry')
 * (WmPackageServiceProvider::injectMenuSectionItems()). Se le due chiavi
 * divergono, in italiano le etichette coincidono per caso («Catasto») e lo
 * sdoppiamento si vede solo in inglese: per questo il test gira in inglese
 * (oc:8672).
 *
 * Con un utente che non e' Administrator la sezione puo' sparire del tutto
 * (oc:8700), e il test passerebbe senza provare nulla.
 */
it('con la lingua inglese il menu ha una sola sezione del Catasto, con le voci di forestas e del package', function () {
    RolesAndPermissionsService::seedDatabase();
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');
    $this->actingAs($admin);
    app()->setLocale('en');

    $request = Request::create('/nova');
    $request->setUserResolver(fn () => $admin);

    // Il package riavvolge il menu di forestas quando Nova serve una
    // richiesta: qui l'evento si lancia a mano.
    ServingNova::dispatch(app(), $request);
    $menu = call_user_func(Nova::$mainMenuCallback, $request);

    $sections = collect($menu)
        ->filter(fn ($section) => $section instanceof MenuSection && (string) $section->name === 'Trail registry')
        ->values();

    expect($sections)->toHaveCount(1);

    $labels = collect($sections[0]->items)->map(fn ($item) => (string) $item->name)->all();

    expect($labels)->toContain('Applications', 'Code registry', 'Anomalies', 'Registry rows', 'SUS API documentation');
});
