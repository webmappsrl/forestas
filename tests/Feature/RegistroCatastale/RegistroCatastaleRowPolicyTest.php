<?php

use App\Models\RegistroCatastaleRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Le righe del registro seguono la regola del Catasto (oc:8700), letta dal
 * package: se la regola cambia li', cambia anche qui.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => RolesAndPermissionsService::seedDatabase());

it('vedono le righe solo Administrator ed Editor, e nessuno le modifica', function (string $role, bool $sees) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $row = new RegistroCatastaleRow;

    expect($user->can('viewAny', RegistroCatastaleRow::class))->toBe($sees)
        ->and($user->can('view', $row))->toBe($sees)
        ->and($user->can('create', RegistroCatastaleRow::class))->toBeFalse()
        ->and($user->can('update', $row))->toBeFalse()
        ->and($user->can('delete', $row))->toBeFalse();
})->with([
    ['Administrator', true],
    ['Editor', true],
    ['Validator', false],
    ['Contributor', false],
]);
