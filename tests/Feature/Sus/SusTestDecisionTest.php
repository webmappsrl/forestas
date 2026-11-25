<?php

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

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

// ---------------------------------------------------------------------------
// L'approvazione dal canale porta le scelte (oc:9038)
// ---------------------------------------------------------------------------

/**
 * Una domanda del canale in istruttoria, con numero riservato e la proposta del
 * SUS in `api_data`. Lo scenario (settore, geometria, `reserve()`) e' quello di
 * ApproveTrailApplicationTest del package, riscritto qui perche' le funzioni dei
 * test di Pest vivono nel file in cui sono scritte e non sono raggiungibili.
 */
function susDecisionProposedApplication(): TrailApplication
{
    Bus::fake();
    Storage::fake('wmfe');
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();

    DB::selectOne(<<<'SQL'
        INSERT INTO taxonomy_wheres (name, properties, geometry, created_at, updated_at)
        VALUES ('ZNUB5', :properties::jsonb, ST_GeomFromText('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))', 4326)::geography, now(), now())
        RETURNING id
    SQL, ['properties' => json_encode(['full_code' => 'ZNUB5', 'source' => 'osm2cai'])]);

    $application = TrailApplication::factory()->create([
        'name' => 'Domanda del monte',
        'source' => 'api',
        'status' => TrailApplicationStatus::UnderReview,
        'api_data' => [
            'activities' => ['hiking', 'mtb'],
            'primary_activity' => 'mtb',
            // Per attivita': il service vuole le famiglie (`hiking-difficulty`).
            'difficulty' => ['hiking' => 'EE'],
        ],
    ]);
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]
    );

    app(TrailRegistryService::class)->reserve($application->refresh());

    return $application;
}

/**
 * Le attivita' e i termini che la proposta chiede, con insert diretti (gli
 * observer scriverebbero su storage). Idempotente: dove le migration hanno gia'
 * creato le famiglie non si duplicano.
 */
function susDecisionSeedTaxonomy(): void
{
    foreach (['hiking', 'mtb'] as $identifier) {
        DB::table('taxonomy_activities')->updateOrInsert(
            ['identifier' => $identifier],
            ['name' => json_encode(['it' => $identifier]), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    foreach (['primary-activity' => ['hiking', 'mtb'], 'hiking-difficulty' => ['E', 'EE']] as $family => $suffixes) {
        DB::table('taxonomy_terms')->updateOrInsert(
            ['identifier' => $family],
            ['name' => json_encode(['it' => $family]), 'created_at' => now(), 'updated_at' => now()],
        );
        $parentId = DB::table('taxonomy_terms')->where('identifier', $family)->value('id');

        foreach ($suffixes as $suffix) {
            DB::table('taxonomy_terms')->updateOrInsert(
                ['identifier' => "{$family}-{$suffix}"],
                ['name' => json_encode(['it' => $suffix]), 'parent_id' => $parentId, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}

it('approvando dal canale conferma la proposta del SUS: attivita, primaria e difficolta', function () {
    susDecisionSeedTaxonomy();
    $application = susDecisionProposedApplication();

    $this->withHeader('Authorization', testToolsClient())
        ->postJson(route('sus.test.approve', ['application' => $application->id]))
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    $track = EcTrack::query()->latest('id')->firstOrFail();
    $properties = $application->fresh()->properties;

    expect($track->taxonomyActivities->pluck('identifier')->all())->toContain('hiking', 'mtb')
        ->and($track->term('primary-activity')->identifier)->toBe('primary-activity-mtb')
        ->and($track->term('hiking-difficulty')->identifier)->toBe('hiking-difficulty-EE')
        ->and($properties['approved_primary_activity'])->toBe('mtb')
        ->and($properties['approved_activities'])->toBe(['hiking', 'mtb']);
});

it('risponde 422 e non 500 se la proposta non si puo scrivere, e la domanda resta in istruttoria', function () {
    susDecisionSeedTaxonomy();
    TaxonomyTerm::where('identifier', 'primary-activity-mtb')->delete();
    $application = susDecisionProposedApplication();
    $before = EcTrack::count();

    $this->withHeader('Authorization', testToolsClient())
        ->postJson(route('sus.test.approve', ['application' => $application->id]))
        ->assertUnprocessable()
        ->assertJsonStructure(['error']);

    expect(EcTrack::count())->toBe($before)
        ->and($application->fresh()->status)->toBe(TrailApplicationStatus::UnderReview);
});
