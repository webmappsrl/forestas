<?php

declare(strict_types=1);

use App\Dto\Api\ApiTrackResponse;
use App\Http\Clients\SardegnaSentieriClient;
use App\Models\EcTrack;
use App\Models\User;
use App\Nova\EcTrack as EcTrackResource;
use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use App\Services\Import\Source\SardegnaSentieriSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\TabsGroup;
use Spatie\Permission\Models\Role;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Lo stato di validazione è un termine della famiglia `validation-status` (oc:8932), non più una
 * colonna con un enum. I tid di Drupal sono il contratto con la sorgente.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Storage::fake('wmfe');
    Storage::disk('wmfe')->put(config('app.name', 'forestas').'/json/icons.json', json_encode(['height' => 1024, 'icons' => []]));

    $user = User::firstOrCreate(
        ['email' => 'forestas@webmapp.it'],
        ['name' => 'Sardegna Sentieri', 'password' => bcrypt('secret')]
    );
    Role::firstOrCreate(['name' => 'Editor', 'guard_name' => 'web']);
    App::withoutEvents(fn () => App::query()->create([
        'id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'name' => 'Sardegna Sentieri',
        'sku' => 'it.webmapp.sardegnasentieri',
        'customer_name' => 'forestas',
        'user_id' => $user->id,
    ]));

    $this->artisan('sardegnasentieri:configure-taxonomy-terms')->assertSuccessful();
});

/**
 * Importa il sentiero 75 con questo stato di Drupal (null = Drupal non ne dice nessuno).
 * Lo stesso service fra una chiamata e l'altra non serve: ogni import rilegge i termini.
 */
function importWithValidationStatus(?string $tid): EcTrack
{
    $taxonomies = $tid === null ? [] : ['stato_di_validazione' => [$tid]];
    $feature = ApiTrackResponse::fromJson([
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[9.19, 41.10, 100.0], [9.20, 41.11, 110.0]]],
        'properties' => [
            'id' => '75',
            'name' => ['it' => 'Sentiero Test'],
            'type' => 'sentiero',
            'updated_at' => '2026-10-01T10:00:00',
            'taxonomies' => $taxonomies,
        ],
    ]);

    $client = Mockery::mock(SardegnaSentieriClient::class.', '.SardegnaSentieriSource::class);
    $client->shouldReceive('getTrackDetail')->andReturn($feature);
    $client->shouldReceive('getTaxonomy', 'getTaxonomyWarnings')->andReturn([]);
    $media = Mockery::mock(SardegnaSentieriMediaSyncService::class);
    $media->shouldIgnoreMissing();

    return (new SardegnaSentieriImportService($client, $client, $media))->importTrack(75);
}

function validationStatusOf(EcTrack $track): array
{
    return $track->fresh()->terms('validation-status')->pluck('identifier')->all();
}

it('traduce ogni id Drupal nel termine giusto', function (string $tid, string $identifier) {
    expect(validationStatusOf(importWithValidationStatus($tid)))->toBe([$identifier]);
})->with([
    'non verificato' => ['4871', 'validation-status-unverified'],
    'non percorribile' => ['4870', 'validation-status-not-walkable'],
    'in revisione-validazione' => ['4869', 'validation-status-under-review'],
    'in pre-accatastamento' => ['4868', 'validation-status-pre-registration'],
    'percorribile' => ['4187', 'validation-status-walkable'],
    'validato' => ['4188', 'validation-status-validated'],
    'certificato' => ['4189', 'validation-status-certified'],
]);

it('tiene i sette id Drupal del contratto con la sorgente', function () {
    $terms = TaxonomyTerm::query()->whereNotNull('parent_id')->pluck('identifier');

    expect(array_keys(SardegnaSentieriImportService::VALIDATION_STATUS_TERMS))
        ->toBe([4871, 4870, 4869, 4868, 4187, 4188, 4189])
        ->and(collect(SardegnaSentieriImportService::VALIDATION_STATUS_TERMS)->every(fn ($identifier) => $terms->contains($identifier)))->toBeTrue();
});

it('al reimport lo stato cambia e non si somma', function () {
    importWithValidationStatus('4868');
    $track = importWithValidationStatus('4187');

    expect(validationStatusOf($track))->toBe(['validation-status-walkable']);
});

it('senza stato in Drupal il valore già scritto resta', function () {
    importWithValidationStatus('4188');
    $track = importWithValidationStatus(null);

    expect(validationStatusOf($track))->toBe(['validation-status-validated']);
});

it('un id sconosciuto svuota lo stato come faceva la colonna', function () {
    importWithValidationStatus('4188');
    $track = importWithValidationStatus('9999');

    expect(validationStatusOf($track))->toBe([]);
});

// ---------------------------------------------------------------------------
// Nova
// ---------------------------------------------------------------------------

/** Il campo «Stato validazione» della scheda: sta fra le classificazioni, nel tab «Generale». */
function validationStatusField(): ?Field
{
    $find = function (array $fields) use (&$find): ?Field {
        foreach ($fields as $field) {
            if (($field->attribute ?? null) === 'validation_status') {
                return $field;
            }
            // I TabsGroup di Nova tengono i campi dei loro tab in `data`, gia' appiattiti.
            foreach (['data', 'fields'] as $key) {
                if (isset($field->$key) && is_array($field->$key) && ($found = $find($field->$key)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    };

    return $find((new EcTrackResource(new EcTrack))->fields(NovaRequest::create('/nova-api/ec-tracks', 'GET')));
}

it('in Nova il campo ha le sette etichette italiane, nell\'ordine di prima', function () {
    $options = collect(validationStatusField()->jsonSerialize()['options'])->mapWithKeys(fn ($o) => [$o['value'] => $o['label']])->all();

    expect($options)->toBe([
        'validation-status-unverified' => 'Non verificato',
        'validation-status-not-walkable' => 'Non percorribile',
        'validation-status-under-review' => 'In revisione-validazione',
        'validation-status-pre-registration' => 'In pre-accatastamento o manutenzione',
        'validation-status-walkable' => 'Percorribile',
        'validation-status-validated' => 'Validato',
        'validation-status-certified' => 'Certificato',
    ]);
});

it('in Nova la scheda mostra l\'etichetta dello stato, non un trattino', function () {
    // `displayUsingLabels()` fa leggere a Nova l'attributo del modello e ignora il
    // `resolveUsing()` del campo (`Field::resolveForDisplay()`): finche' `validation_status` non
    // e' stato un attributo vero, la scheda restava vuota anche con il termine assegnato. Il
    // test che usa `resolve()` non se ne accorge, questo si' (oc:8932).
    $track = importWithValidationStatus('4868');

    $field = validationStatusField();
    $field->resolveForDisplay($track->fresh());

    expect($field->jsonSerialize()['displayedAs'])->toBe('In pre-accatastamento o manutenzione');
});

it('in Nova lo stato apre il tab «Generale» delle classificazioni', function () {
    // Prima di Tipo percorso e delle altre tassonomie: e' il primo dato che chi compila cerca.
    $resource = new EcTrackResource(new EcTrack);
    $tabs = new ReflectionMethod($resource, 'taxonomyTabs');
    $tabs->setAccessible(true);

    $general = collect($tabs->invoke($resource))->firstWhere('name', __('General'));
    $names = collect($general->data ?? [])->map(fn ($f) => $f->name)->unique()->values()->all();

    expect($names[0] ?? null)->toBe(__('Validation status'));
});

it('in Nova il campo legge il termine e lo scrive, sostituendolo', function () {
    $track = importWithValidationStatus('4868');
    $field = validationStatusField();

    $field->resolve($track->fresh());
    expect($field->value)->toBe('validation-status-pre-registration');

    $request = NovaRequest::create('/nova-api/ec-tracks/'.$track->id, 'PUT', ['validation_status' => 'validation-status-certified']);
    $after = $field->fill($request, $track);
    $after();
    expect(validationStatusOf($track))->toBe(['validation-status-certified']);

    $after = $field->fill(NovaRequest::create('/', 'PUT', ['validation_status' => '']), $track->fresh());
    $after();
    expect(validationStatusOf($track))->toBe([]);
});

it('in Nova lo stato non compare anche fra i campi generici delle tassonomie', function () {
    $fields = collect((new EcTrackResource(new EcTrack))->fields(NovaRequest::create('/nova-api/ec-tracks', 'GET')));
    $attributes = $fields->flatMap(fn ($f) => $f instanceof TabsGroup
        ? collect($f->tabs)->flatMap(fn ($tab) => collect($tab->data)->pluck('attribute'))
        : [$f->attribute ?? null])->filter()->all();

    expect($attributes)->not->toContain('taxonomy_validation_status');
});

// ---------------------------------------------------------------------------
// Spostamento dei dati esistenti
// ---------------------------------------------------------------------------

function bareTrack(): EcTrack
{
    $track = Wm\WmPackage\Models\EcTrack::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => ['ref' => 'TEST'],
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z((9 40.5 0, 9.1 40.5 0))', 4326)"),
    ]);

    return EcTrack::query()->findOrFail($track->id);
}

/** Rimette la colonna come l'aveva un database creato prima di oc:8932. */
function restoreLegacyColumn(): void
{
    Schema::table('ec_tracks', fn ($table) => $table->string('stato_validazione')->nullable());
}

it('sposta la colonna nei termini e poi la toglie', function () {
    $senza = bareTrack();
    $con = bareTrack();
    $conAltro = bareTrack();
    $giaTermine = importWithValidationStatus('4188');

    restoreLegacyColumn();
    DB::table('ec_tracks')->where('id', $con->id)->update(['stato_validazione' => 'in_pre_accatastamento']);
    DB::table('ec_tracks')->where('id', $conAltro->id)->update(['stato_validazione' => 'certificato']);
    // Il termine già scritto vince sulla colonna.
    DB::table('ec_tracks')->where('id', $giaTermine->id)->update(['stato_validazione' => 'non_verificato']);

    $this->artisan('sardegnasentieri:move-validation-status')->assertSuccessful();

    expect(validationStatusOf($con))->toBe(['validation-status-pre-registration'])
        ->and(validationStatusOf($conAltro))->toBe(['validation-status-certified'])
        ->and(validationStatusOf($giaTermine))->toBe(['validation-status-validated'])
        ->and(validationStatusOf($senza))->toBe([])
        ->and(Schema::hasColumn('ec_tracks', 'stato_validazione'))->toBeFalse();
});

it('è rilanciabile e non dice niente se la colonna non c\'è', function () {
    $this->artisan('sardegnasentieri:move-validation-status')->assertSuccessful();
    expect(Schema::hasColumn('ec_tracks', 'stato_validazione'))->toBeFalse();
});

it('con --dry-run non scrive e non toglie la colonna', function () {
    $track = bareTrack();
    restoreLegacyColumn();
    DB::table('ec_tracks')->where('id', $track->id)->update(['stato_validazione' => 'validato']);

    $this->artisan('sardegnasentieri:move-validation-status', ['--dry-run' => true])->assertSuccessful();

    expect(validationStatusOf($track))->toBe([])
        ->and(Schema::hasColumn('ec_tracks', 'stato_validazione'))->toBeTrue();
});

it('un valore sconosciuto ferma il comando e la colonna resta', function () {
    $track = bareTrack();
    restoreLegacyColumn();
    DB::table('ec_tracks')->where('id', $track->id)->update(['stato_validazione' => 'inventato']);

    $this->artisan('sardegnasentieri:move-validation-status')->assertFailed();

    expect(Schema::hasColumn('ec_tracks', 'stato_validazione'))->toBeTrue();
});

it('senza i termini il comando chiede prima l\'allineamento', function () {
    restoreLegacyColumn();
    $family = TaxonomyTerm::query()->where('identifier', 'validation-status')->first();
    $family->children()->delete();
    $family->delete();

    $this->artisan('sardegnasentieri:move-validation-status')->assertFailed();
});
