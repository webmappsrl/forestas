<?php

use App\Models\User;
use App\Nova\EcTrack as EcTrackResource;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Models\EcTrack as PackageEcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Services\GeoJsonService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Collegare un'attività fa scattare un observer sul pivot che scrive su storage: in test
    // il disco S3 non è configurato e l'attach esplode. Con il disco finto non succede, e il
    // file delle icone semplicemente non c'è — i termini restano senza icona, come previsto.
    Storage::fake('wmfe');
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    app()->setLocale('it');
    // Niente factory per le tassonomie nel package: si creano a mano, come nei suoi test.
    $this->hiking = TaxonomyActivity::create(['name' => ['it' => 'Escursionismo'], 'icon' => 'txn-trekking']);
    $this->bike = TaxonomyActivity::create(['name' => ['it' => 'Bicicletta'], 'icon' => null]);
});

function tabField($resource, string $tab, string $attribute, bool $formOnly = true)
{
    foreach ($resource->fields(NovaRequest::create('/nova-api/ec-tracks', 'GET')) as $field) {
        if (! $field instanceof TabsGroup) {
            continue;
        }
        foreach ($field->tabs as $t) {
            if (((string) $t->name) !== $tab) {
                continue;
            }
            foreach ($t->data as $f) {
                // onlyOnForms() spegne showOnDetail; onlyOnDetail() spegne showOnUpdate.
                $onForm = (bool) ($f->showOnUpdate ?? false);
                if ($f->attribute === $attribute && $onForm === $formOnly) {
                    return $f;
                }
            }
        }
    }

    return null;
}

it('mostra le attività nel loro tab, come una tassonomia', function () {
    $track = PackageEcTrack::factory()->createQuietly();

    $field = tabField(new EcTrackResource($track), __('Activities'), 'legacy_taxonomy_activities');

    expect($field)->not->toBeNull()
        ->and($field->component)->toBe('choice');

    $options = collect($field->meta()['options']);
    expect($options->pluck('label')->all())->toContain('Escursionismo', 'Bicicletta')
        // L'icona c'è dove il dato ce l'ha, e manca dove non c'è: niente icona inventata.
        ->and($options->firstWhere('label', 'Bicicletta'))->not->toHaveKey('svg');
});

it('non lascia più il pannello Activities in fondo alla scheda', function () {
    $track = PackageEcTrack::factory()->createQuietly();

    $attributi = array_map(
        fn ($f) => $f->attribute,
        (new EcTrackResource($track))->fields(NovaRequest::create('/nova-api/ec-tracks', 'GET'))
    );

    expect($attributi)->not->toContain('taxonomyActivities');
});

it('salva le attività scelte e conserva le durate del pivot', function () {
    $track = PackageEcTrack::factory()->createQuietly();
    $track->taxonomyActivities()->attach($this->hiking->id, ['duration_forward' => 120, 'duration_backward' => 100]);
    $this->rigaIniziale = DB::table('taxonomy_activityables')
        ->where('taxonomy_activity_id', $this->hiking->id)
        ->where('taxonomy_activityable_id', $track->id)
        ->value('id');

    $field = tabField(new EcTrackResource($track), __('Activities'), 'legacy_taxonomy_activities');
    $request = NovaRequest::create('/nova-api/ec-tracks/'.$track->id, 'PUT', [
        'legacy_taxonomy_activities' => $this->hiking->id.','.$this->bike->id,
    ]);
    ($field->fill($request, $track))();

    $track->refresh();
    expect($track->taxonomyActivities->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->hiking->id, $this->bike->id])->sort()->values()->all());

    // Quella che c'era già non è stata staccata e riattaccata: stessa riga di pivot, stessa
    // durata. È il motivo per cui il campo sincronizza per differenza invece di rifare tutto.
    $row = DB::table('taxonomy_activityables')
        ->where('taxonomy_activity_id', $this->hiking->id)
        ->where('taxonomy_activityable_id', $track->id)
        ->first();
    expect($row->id)->toBe($this->rigaIniziale)
        ->and((int) $row->duration_forward)->toBe(120);
});

it('toglie un\'attività deselezionata', function () {
    $track = PackageEcTrack::factory()->createQuietly();
    $track->taxonomyActivities()->attach([$this->hiking->id, $this->bike->id]);

    $field = tabField(new EcTrackResource($track), __('Activities'), 'legacy_taxonomy_activities');
    $request = NovaRequest::create('/nova-api/ec-tracks/'.$track->id, 'PUT', [
        'legacy_taxonomy_activities' => (string) $this->bike->id,
    ]);
    ($field->fill($request, $track))();

    expect($track->refresh()->taxonomyActivities->pluck('id')->all())->toBe([$this->bike->id]);
});

it('il dettaglio mostra nome e icona, non gli id', function () {
    $track = PackageEcTrack::factory()->createQuietly();
    $track->taxonomyActivities()->attach($this->hiking->id);

    $field = tabField(new EcTrackResource($track), __('Activities'), 'legacy_taxonomy_activities', formOnly: false);
    $field->resolve($track);

    // I path dell'icona contengono cifre: si verifica il nome, non l'assenza di un numero.
    expect(strip_tags((string) $field->value))->toBe('Escursionismo');
});

it('non cambia cosa leggono le app: GeoJSON e indice restano identici', function () {
    $track = PackageEcTrack::factory()->createQuietly();

    $field = tabField(new EcTrackResource($track), __('Activities'), 'legacy_taxonomy_activities');
    ($field->fill(
        NovaRequest::create('/nova-api/ec-tracks/'.$track->id, 'PUT', [
            'legacy_taxonomy_activities' => (string) $this->hiking->id,
        ]),
        $track
    ))();
    $track->refresh();

    // Le due uscite verso le app: il GeoJSON (properties.taxonomy.activity) e l'indice Scout
    // (taxonomyActivities). Il campo cambia come si sceglie in Nova, non cosa esce di qui.
    $geojson = (new GeoJsonService)->getModelAsGeojson($track);
    expect(data_get($geojson, 'properties.taxonomy.activity'))->toHaveCount(1);

    expect($track->toSearchableArray()['taxonomyActivities'])->not->toBeEmpty();
});

it('apre la pagina di modifica di un sentiero con attività collegate', function () {
    $track = PackageEcTrack::factory()->createQuietly();
    $track->taxonomyActivities()->attach($this->hiking->id);

    // Il resolve del campo fa un join: senza qualificare la colonna, «id» è ambiguo e la
    // pagina di modifica risponde 500 (oc:8910).
    $field = tabField(new EcTrackResource($track), __('Activities'), 'legacy_taxonomy_activities');
    $field->resolve($track);

    expect((string) $field->value)->toBe((string) $this->hiking->id);
});

it('la pagina di modifica risponde: Nova valuta anche le regole dei campi', function () {
    RolesAndPermissionsService::seedDatabase();
    $admin = User::factory()->create();
    $admin->assignRole('Administrator');

    $track = PackageEcTrack::factory()->createQuietly();
    $track->taxonomyActivities()->attach($this->hiking->id);

    // È l'endpoint che la pagina di modifica chiama per costruire il form: passa da getRules(),
    // dove una Closure nuda in rules() verrebbe invocata con la NovaRequest (oc:8910).
    $this->actingAs($admin)
        ->getJson('/nova-api/ec-tracks/'.$track->id.'/update-fields?editing=true')
        ->assertOk();
});

it('mostra una scala di difficoltà solo se la sua attività è fra quelle scelte', function () {
    $mtb = TaxonomyActivity::create(['identifier' => 'mtb', 'name' => ['it' => 'mtb']]);
    $track = PackageEcTrack::factory()->createQuietly();
    // La traccia nasce con la sua app: le tassonomie vanno accese lì, altrimenti il tab è vuoto.
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');

    $field = tabField(new EcTrackResource($track), __('Activities'), 'taxonomy_mtb_difficulty');
    expect($field)->not->toBeNull();

    // La dipendenza punta a una tassonomia che vive in tabella sua, non a una tassonomia.
    $dependencies = (new ReflectionProperty($field, 'fieldDependencies'))->getValue($field);
    expect($dependencies)->not->toBeEmpty();

    $visibleWith = function ($value) use ($field, $dependencies) {
        $request = NovaRequest::create('/nova-api/ec-tracks/field/x', 'PATCH', ['legacy_taxonomy_activities' => $value]);
        foreach ($dependencies as $d) {
            $d->handle($field, $request);
        }

        return $field->visible ?? true;
    };

    expect($visibleWith((string) $mtb->id))->toBeTrue()
        ->and($visibleWith((string) $this->hiking->id))->toBeFalse()
        // Nessuna attività scelta: il campo resta, per non far sparire un dato in compilazione.
        ->and($visibleWith(''))->toBeTrue();
});

it('non offre fra le attività i tipi di percorso, ma non li cancella', function () {
    // Si scrive come fa l'import, con insertOrIgnore: salvando da Eloquent l'observer passa
    // l'identifier per Str::slug() e i due punti diventano trattini, quindi la riga non
    // somiglierebbe a quelle vere.
    $identifier = SardegnaSentieriImportService::TYPE_ACTIVITY_PREFIX.'sentiero';
    DB::table('taxonomy_activities')->insert([
        'identifier' => $identifier,
        'name' => json_encode(['it' => 'Sentiero']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $routeType = TaxonomyActivity::where('identifier', $identifier)->first();
    $track = PackageEcTrack::factory()->createQuietly();
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');

    $field = tabField(new EcTrackResource($track), __('Activities'), 'legacy_taxonomy_activities');
    $labels = collect($field->meta()['options'])->pluck('label')->all();

    expect($labels)->toContain('Escursionismo')
        ->and($labels)->not->toContain('Sentiero')
        // Resta in tabella: i layer «Sentieri», «Itinerari» e «Cammini di Sardegna» associano
        // le tracce proprio con queste attività.
        ->and(TaxonomyActivity::find($routeType->id))->not->toBeNull();
});

it('non stacca un\'attività nascosta quando si salva la scheda', function () {
    // Il tipo percorso non compare fra le options: salvando, non deve sparire dal contenuto.
    $identifier = SardegnaSentieriImportService::TYPE_ACTIVITY_PREFIX.'sentiero';
    DB::table('taxonomy_activities')->insert([
        'identifier' => $identifier,
        'name' => json_encode(['it' => 'Sentiero']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $hidden = TaxonomyActivity::where('identifier', $identifier)->first();

    $track = PackageEcTrack::factory()->createQuietly();
    $track->taxonomyActivities()->attach([$hidden->id, $this->hiking->id]);
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');

    $field = tabField(new EcTrackResource($track), __('Activities'), 'legacy_taxonomy_activities');
    ($field->fill(
        NovaRequest::create('/nova-api/ec-tracks/'.$track->id, 'PUT', [
            'legacy_taxonomy_activities' => (string) $this->bike->id,
        ]),
        $track
    ))();

    expect($track->fresh()->taxonomyActivities->pluck('id')->sort()->values()->all())
        ->toBe(collect([$hidden->id, $this->bike->id])->sort()->values()->all());
});
