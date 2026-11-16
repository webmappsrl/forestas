<?php

declare(strict_types=1);

use App\Nova\Dashboards\Main;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\TrailRegistry\Nova\Cards\TrailRegistryInconsistenciesCard;
use Wm\WmPackage\TrailRegistry\Nova\Cards\WalkabilityDistributionCard;

/**
 * La dashboard di Nova mostra lo stato del Catasto al posto della pagina di
 * benvenuto (oc:8989). Le card vengono dal package, lo shard le monta.
 */
it('mostra le due card del Catasto', function () {
    $cards = collect((new Main)->cards());

    expect($cards->first(fn ($card) => $card instanceof WalkabilityDistributionCard))->not->toBeNull()
        ->and($cards->first(fn ($card) => $card instanceof TrailRegistryInconsistenciesCard))->not->toBeNull();
});

it('non mostra più la pagina di benvenuto di Nova', function () {
    $cards = collect((new Main)->cards());

    expect($cards->first(fn ($card) => $card instanceof \Laravel\Nova\Cards\Help))->toBeNull();
});

it('si costruisce senza errori per ogni ruolo che entra in Nova', function (string $role) {
    // I ruoli non esistono in un database appena migrato: li crea il seeder del package, come
    // negli altri test che fanno agire un utente con un ruolo.
    RolesAndPermissionsService::seedDatabase();

    // Le card sono numeri d'insieme: le vede chiunque entri in Nova, senza
    // restrizione per ruolo. `Guest` e `Sus` in Nova non entrano affatto.
    $user = \Wm\WmPackage\Models\User::factory()->create();
    $user->assignRole($role);
    auth()->login($user);

    expect(fn () => (new Main)->cards())->not->toThrow(Exception::class);
})->with(['Administrator', 'Editor', 'Validator', 'Contributor']);
