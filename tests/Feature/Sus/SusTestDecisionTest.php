<?php

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;

// Lo strumento di prova del canale (config/sus.php): approva o respinge una
// domanda come farebbe l'istruttore. La decisione vera e' coperta dai test del
// service nel package; qui si prova cio' che e' dello shard: dove esiste, chi
// lo usa, su quali domande.

function testToolsClient(): string
{
    Role::findOrCreate('Sus', 'web');
    $client = User::factory()->create();
    $client->assignRole('Sus');

    return 'Bearer '.JWTAuth::fromUser($client);
}

function apiApplication(TrailApplicationStatus $status): TrailApplication
{
    return TrailApplication::factory()->create(['source' => 'api', 'status' => $status]);
}

beforeEach(function () {
    config(['sus.test_tools' => true]);
});

it('dove lo strumento e spento risponde 404', function () {
    config(['sus.test_tools' => false]);
    $application = apiApplication(TrailApplicationStatus::UnderReview);

    $this->withHeader('Authorization', testToolsClient())
        ->postJson(route('sus.test.reject', ['application' => $application->id]), ['rejection_reason' => 'x'])
        ->assertNotFound();
    expect($application->fresh()->status)->toBe(TrailApplicationStatus::UnderReview);
});

it('rifiuta chi non ha un token', function () {
    $application = apiApplication(TrailApplicationStatus::UnderReview);

    $this->postJson(route('sus.test.approve', ['application' => $application->id]))
        ->assertUnauthorized();
});

it('non lascia decidere a chi non e il client del canale', function () {
    $application = apiApplication(TrailApplicationStatus::UnderReview);

    $this->actingAs(User::factory()->create(), 'api')
        ->postJson(route('sus.test.approve', ['application' => $application->id]))
        ->assertForbidden();
});

it('respinge una domanda in istruttoria e salva il motivo', function () {
    $application = apiApplication(TrailApplicationStatus::UnderReview);

    $this->withHeader('Authorization', testToolsClient())
        ->postJson(route('sus.test.reject', ['application' => $application->id]), [
            'rejection_reason' => 'Tracciato sovrapposto al sentiero 105',
        ])
        ->assertOk()
        ->assertExactJson([
            'id' => $application->id,
            'status' => 'rejected',
            'rejection_reason' => 'Tracciato sovrapposto al sentiero 105',
        ]);

    expect($application->fresh()->status)->toBe(TrailApplicationStatus::Rejected);
});

it('per respingere chiede il motivo', function () {
    $application = apiApplication(TrailApplicationStatus::UnderReview);

    $this->withHeader('Authorization', testToolsClient())
        ->postJson(route('sus.test.reject', ['application' => $application->id]), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rejection_reason');
});

it('decide solo una domanda in istruttoria: le altre rispondono 409 con lo stato', function (TrailApplicationStatus $status) {
    $application = apiApplication($status);

    $this->withHeader('Authorization', testToolsClient())
        ->postJson(route('sus.test.approve', ['application' => $application->id]))
        ->assertStatus(409)
        ->assertJsonPath('status', $status->value);
})->with([
    'in compilazione' => [TrailApplicationStatus::UnderCompilation],
    'gia respinta' => [TrailApplicationStatus::Rejected],
]);

it('non tocca le domande create d ufficio in Nova', function () {
    $application = TrailApplication::factory()->create(['source' => 'office', 'status' => TrailApplicationStatus::UnderReview]);

    $this->withHeader('Authorization', testToolsClient())
        ->postJson(route('sus.test.reject', ['application' => $application->id]), ['rejection_reason' => 'x'])
        ->assertNotFound()
        ->assertExactJson(['error' => __('Application not found.')]);
});

it('non compare nella documentazione delle API', function () {
    $this->get('/docs/api/sus.json')
        ->assertOk()
        ->assertDontSee('docs/api/sus/test');
});
