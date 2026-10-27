<?php

use App\Models\User;
use App\Nova\Filters\EcTrackCadastralAreaFilter;
use App\Nova\Filters\EcTrackRuoloFilter;
use App\Services\Import\TaxonomyWhereLayersImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Filtri della lista Nova delle EC Tracce (oc:8806, oc:8825): le tipologie restano nel filtro dei
 * layer, le aree catastali hanno un filtro a parte, a scelta multipla in OR.
 *
 * Le aree sono tre quadrati affiancati: B (lon 9-10), C (10-11), G (11-12), lat 40-41.
 */
uses(RefreshDatabase::class);

const LAYER_FILTER_KEY = 'features_by_layer_ecTracks';

beforeEach(function () {
    Bus::fake();
    RolesAndPermissionsService::seedDatabase();
    App::factory()->createQuietly();
});

function amministratore(): User
{
    $user = User::factory()->create();
    $user->assignRole('Administrator');

    return $user;
}

function taxonomyWhereConGeometria(string $nome, array $properties, string $wkt): TaxonomyWhere
{
    $where = TaxonomyWhere::create(['name' => ['it' => $nome, 'en' => $nome], 'properties' => $properties]);
    DB::update('UPDATE taxonomy_wheres SET geometry = ST_GeomFromText(?, 4326)::geography WHERE id = ?', [$wkt, $where->id]);

    return $where;
}

function quadrato(int $lonMin): string
{
    $lonMax = $lonMin + 1;

    return "POLYGON(({$lonMin} 40, {$lonMin} 41, {$lonMax} 41, {$lonMax} 40, {$lonMin} 40))";
}

function areaCatastale(string $nome, int $lonMin): TaxonomyWhere
{
    $area = taxonomyWhereConGeometria($nome, ['source' => TaxonomyWhereLayersImportService::SOURCE_KEY], quadrato($lonMin));
    Layer::factory()->createQuietly(['name' => ['it' => $nome, 'en' => $nome], 'properties' => []])
        ->taxonomyWheres()->attach($area->id);

    return $area;
}

function traccia(float $lonDa, float $lonA, array $properties = []): EcTrack
{
    // properties sempre oggetto, come nei dati veri: un array JSON vuoto fa fallire jsonb_set().
    return EcTrack::factory()->createQuietly([
        'properties' => $properties + ['ref' => 'TEST'],
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z(({$lonDa} 40.5 0, {$lonA} 40.5 0))', 4326)"),
    ]);
}

function calcolaAree(): void
{
    GeometryComputationService::make()->syncTaxonomyWhere(config('wm-package.ec_track_model', EcTrack::class));
}

/**
 * @param  array<string, mixed>  $filtri  chiave del filtro => valore
 * @return list<int>
 */
function idFiltrati($test, array $filtri): array
{
    $codificati = base64_encode(json_encode(collect($filtri)->map(fn ($v, $k) => [$k => $v])->values()->all()));

    return $test->actingAs(amministratore())
        ->getJson('/nova-api/ec-tracks?perPage=100&filters='.$codificati)
        ->assertOk()
        ->json('resources.*.id.value');
}

/**
 * @return array<string, mixed>
 */
function definizioneFiltro($test, string $chiave): array
{
    $filtri = $test->actingAs(amministratore())->getJson('/nova-api/ec-tracks/filters')->assertOk()->json();

    return collect($filtri)->firstWhere('class', $chiave);
}

/**
 * @return list<int>
 */
function scelte(TaxonomyWhere ...$aree): array
{
    return collect($aree)->map(fn ($area) => $area->id)->values()->all();
}

it('il filtro dei layer elenca solo le tipologie e conserva etichetta e chiave', function () {
    areaCatastale('Area B', 9);
    Layer::factory()->createQuietly(['name' => ['it' => 'Sentieri', 'en' => 'Sentieri'], 'properties' => []]);

    $filtro = definizioneFiltro($this, LAYER_FILTER_KEY);

    expect($filtro['name'])->toBe(__('Layer'))
        ->and(collect($filtro['options'])->pluck('label')->all())->toBe(['Sentieri']);
});

it('un layer legato a una where di altra sorgente resta nel filtro dei layer', function () {
    $comune = taxonomyWhereConGeometria('Nuoro', ['source' => 'osmfeatures'], quadrato(30));
    Layer::factory()->createQuietly(['name' => ['it' => 'Itinerari', 'en' => 'Itinerari'], 'properties' => []])
        ->taxonomyWheres()->attach($comune->id);

    $filtro = definizioneFiltro($this, LAYER_FILTER_KEY);

    expect(collect($filtro['options'])->pluck('label')->all())->toBe(['Itinerari']);
});

it('il filtro aree catastali elenca le aree ordinate per nome', function () {
    $areaG = areaCatastale('Area G', 11);
    $areaB = areaCatastale('Area B', 9);
    $areaC = areaCatastale('Area C', 10);
    taxonomyWhereConGeometria('Nuoro', ['source' => 'osmfeatures'], quadrato(30));

    $filtro = definizioneFiltro($this, EcTrackCadastralAreaFilter::class);

    expect($filtro['name'])->toBe(__('Cadastral areas'))
        ->and($filtro['component'])->toBe('nova-multiselect-filter')
        ->and($filtro['placeholder'])->toBe(__('Select cadastral area'))
        ->and($filtro['options'])->toBe([
            ['label' => 'Area B', 'value' => $areaB->id],
            ['label' => 'Area C', 'value' => $areaC->id],
            ['label' => 'Area G', 'value' => $areaG->id],
        ]);
});

it('una sola area: le tracce che la attraversano, anche per un tratto breve', function () {
    $areaB = areaCatastale('Area B', 9);
    areaCatastale('Area C', 10);
    $dentroB = traccia(9.2, 9.4);
    $acavallo = traccia(9.95, 10.8);
    traccia(10.2, 10.4);
    calcolaAree();

    expect(idFiltrati($this, [EcTrackCadastralAreaFilter::class => scelte($areaB)]))
        ->toEqualCanonicalizing([$dentroB->id, $acavallo->id]);
});

it('più aree scelte valgono in OR', function () {
    $areaB = areaCatastale('Area B', 9);
    areaCatastale('Area C', 10);
    $areaG = areaCatastale('Area G', 11);
    $dentroB = traccia(9.2, 9.4);
    traccia(10.2, 10.4);
    $dentroG = traccia(11.2, 11.4);
    calcolaAree();

    expect(idFiltrati($this, [EcTrackCadastralAreaFilter::class => scelte($areaB, $areaG)]))
        ->toEqualCanonicalizing([$dentroB->id, $dentroG->id]);
});

it('nessuna area scelta non restringe nulla', function () {
    $areaB = areaCatastale('Area B', 9);
    $tracce = [traccia(9.2, 9.4)->id, traccia(20, 20.5)->id];
    calcolaAree();

    expect(idFiltrati($this, [EcTrackCadastralAreaFilter::class => []]))
        ->toEqualCanonicalizing($tracce);
});

it('tipologia e area si combinano in AND', function () {
    // L'attività legge le icone dal disco wmfe: senza disco finto andrebbe su S3.
    Storage::fake('wmfe');
    Storage::disk('wmfe')->put(config('app.name', 'forestas').'/json/icons.json', json_encode(['height' => 1024, 'icons' => []]));
    $areaB = areaCatastale('Area B', 9);
    $attivita = TaxonomyActivity::createQuietly(['name' => ['it' => 'Sentiero', 'en' => 'Trail'], 'identifier' => 'sentiero']);
    $sentieri = Layer::factory()->createQuietly(['name' => ['it' => 'Sentieri', 'en' => 'Sentieri'], 'properties' => []]);
    $sentieri->taxonomyActivities()->attach($attivita->id);

    $sentieroInB = traccia(9.2, 9.4);
    $sentieroInB->taxonomyActivities()->attach($attivita->id);
    traccia(9.5, 9.6);
    traccia(20, 20.5)->taxonomyActivities()->attach($attivita->id);
    calcolaAree();

    expect(idFiltrati($this, [
        LAYER_FILTER_KEY => $sentieri->id,
        EcTrackCadastralAreaFilter::class => scelte($areaB),
    ]))->toBe([$sentieroInB->id]);
});

it('una where osm2cai con osm2cai_id uguale all id di un area non fa uscire le sue tracce', function () {
    $areaB = areaCatastale('Area B', 9);
    taxonomyWhereConGeometria('ZNUB2', ['source' => 'osm2cai', 'osm2cai_id' => $areaB->id], quadrato(20));
    traccia(20.2, 20.4);
    $dentroB = traccia(9.2, 9.4);
    calcolaAree();

    expect(idFiltrati($this, [EcTrackCadastralAreaFilter::class => scelte($areaB)]))->toBe([$dentroB->id]);
});

it('legge anche la voce dell area scritta nella forma di oc:8487', function () {
    $areaB = areaCatastale('Area B', 9);
    $vecchia = traccia(40, 40.5, ['taxonomy_where' => [
        (string) $areaB->id => ['name' => ['it' => 'Area B'], 'source' => TaxonomyWhereLayersImportService::SOURCE_KEY],
    ]]);
    calcolaAree();

    expect(idFiltrati($this, [EcTrackCadastralAreaFilter::class => scelte($areaB)]))->toBe([$vecchia->id]);
});

/*
 * Filtro dei layer con le tracce del layer, non le candidate di onLayer() (oc:8825).
 */

function layerTipologia(string $nome): Layer
{
    return Layer::factory()->createQuietly(['name' => ['it' => $nome, 'en' => $nome], 'properties' => []]);
}

function attivitaSentiero(): TaxonomyActivity
{
    // L'attività legge le icone dal disco wmfe: senza disco finto andrebbe su S3.
    Storage::fake('wmfe');
    Storage::disk('wmfe')->put(config('app.name', 'forestas').'/json/icons.json', json_encode(['height' => 1024, 'icons' => []]));

    return TaxonomyActivity::createQuietly(['name' => ['it' => 'Sentiero', 'en' => 'Trail'], 'identifier' => 'sentiero']);
}

it('un layer senza attività con tracce associate restituisce solo quelle', function () {
    $cammini = layerTipologia('Cammini di Sardegna');
    $associate = [traccia(9.2, 9.4)->id, traccia(9.5, 9.6)->id];
    traccia(10.2, 10.4);
    traccia(11.2, 11.4);
    $cammini->ecTracks()->attach($associate);

    expect(idFiltrati($this, [LAYER_FILTER_KEY => $cammini->id]))->toEqualCanonicalizing($associate);
});

it('un layer in automatico con attività resta su onLayer anche se ha un associata diversa', function () {
    $attivita = attivitaSentiero();
    $sentieri = layerTipologia('Sentieri');
    $sentieri->taxonomyActivities()->attach($attivita->id);
    $sentiero = traccia(9.2, 9.4);
    $sentiero->taxonomyActivities()->attach($attivita->id);
    $altra = traccia(10.2, 10.4);
    $sentieri->ecTracks()->attach($altra->id);

    expect($sentieri->fresh()->isAutoTrackMode())->toBeTrue()
        ->and(idFiltrati($this, [LAYER_FILTER_KEY => $sentieri->id]))->toBe([$sentiero->id]);
});

it('un layer in automatico con aree nelle properties resta su onLayer anche se ha un associata diversa', function () {
    $sentieri = Layer::factory()->createQuietly([
        'name' => ['it' => 'Sentieri del Nuorese', 'en' => 'Sentieri del Nuorese'],
        'properties' => ['taxonomy_where' => ['nuoro' => ['it' => 'Nuoro']]],
    ]);
    $nelNuorese = traccia(9.2, 9.4, ['taxonomy_where' => ['nuoro' => ['it' => 'Nuoro']]]);
    $altra = traccia(10.2, 10.4);
    $sentieri->ecTracks()->attach($altra->id);

    expect(idFiltrati($this, [LAYER_FILTER_KEY => $sentieri->id]))->toBe([$nelNuorese->id]);
});

it('un layer in modalità manuale restituisce le tracce associate anche se ha un attività', function () {
    $attivita = attivitaSentiero();
    $layer = layerTipologia('Sentieri scelti');
    $layer->taxonomyActivities()->attach($attivita->id);
    $layer->setTrackMode('manual');
    traccia(9.2, 9.4)->taxonomyActivities()->attach($attivita->id);
    $scelta = traccia(10.2, 10.4);
    $layer->ecTracks()->attach($scelta->id);

    expect(idFiltrati($this, [LAYER_FILTER_KEY => $layer->id]))->toBe([$scelta->id]);
});

it('un layer senza attività né tracce associate restituisce tutte le tracce come oggi', function () {
    $vuoto = layerTipologia('Sentieri accessibili');
    $tutte = [traccia(9.2, 9.4)->id, traccia(10.2, 10.4)->id];

    expect(idFiltrati($this, [LAYER_FILTER_KEY => $vuoto->id]))->toEqualCanonicalizing($tutte);
});

it('le tracce associate si combinano con le aree catastali', function () {
    $areaB = areaCatastale('Area B', 9);
    $cammini = layerTipologia('Cammini di Sardegna');
    $camminoInB = traccia(9.2, 9.4);
    $camminoInC = traccia(10.2, 10.4);
    traccia(9.5, 9.6);
    $cammini->ecTracks()->attach([$camminoInB->id, $camminoInC->id]);
    calcolaAree();

    expect(idFiltrati($this, [
        LAYER_FILTER_KEY => $cammini->id,
        EcTrackCadastralAreaFilter::class => scelte($areaB),
    ]))->toBe([$camminoInB->id]);
});

/*
 * Verifica dei filtri della lista (oc:8875): App e Include/Exclude per id fuori dal menu, Ruolo e
 * Stato validazione fissati sul comportamento verificato nel browser, titoli tradotti.
 */

/**
 * @return list<string>
 */
function chiaviFiltri($test): array
{
    return collect($test->actingAs(amministratore())->getJson('/nova-api/ec-tracks/filters')->assertOk()->json())
        ->pluck('class')->all();
}

function conEnte(EcTrack $traccia, string $ruolo): void
{
    $enteId = DB::table('entes')->insertGetId([
        'sardegnasentieri_id' => random_int(1, 1_000_000),
        'name' => json_encode(['it' => 'Ente '.$ruolo]),
    ]);
    // Come l'import: enteable_type è il model di forestas (SardegnaSentieriImportService).
    DB::table('enteables')->insert([
        'ente_id' => $enteId,
        'enteable_id' => $traccia->id,
        'enteable_type' => \App\Models\EcTrack::class,
        'ruolo' => $ruolo,
    ]);
}

function conStato(EcTrack $traccia, ?string $stato): EcTrack
{
    DB::table('ec_tracks')->where('id', $traccia->id)->update(['stato_validazione' => $stato]);

    return $traccia;
}

it('il menu dei filtri non ha App né Include/Exclude per id', function () {
    $chiavi = chiaviFiltri($this);

    expect($chiavi)->not->toContain('resource:apps:app')
        ->and(collect($chiavi)->filter(fn (string $chiave) => str_starts_with($chiave, 'features_include_ids_')
            || str_starts_with($chiave, 'features_exclude_ids_'))->all())->toBe([])
        ->and($chiavi)->toContain(
            LAYER_FILTER_KEY,
            EcTrackCadastralAreaFilter::class,
            EcTrackRuoloFilter::class,
            'Select:stato_validazione',
        );
});

it('il filtro Ruolo restituisce le tracce con un ente in quel ruolo', function () {
    $conGestore = traccia(9.2, 9.4);
    conEnte($conGestore, 'gestore');
    conEnte(traccia(9.5, 9.6), 'rilevatore');
    traccia(9.7, 9.8);

    expect(idFiltrati($this, [EcTrackRuoloFilter::class => 'gestore']))->toBe([$conGestore->id]);
});

it('il filtro Stato validazione restituisce le tracce con quel valore', function () {
    $percorribile = conStato(traccia(9.2, 9.4), 'percorribile');
    conStato(traccia(9.5, 9.6), 'in_revisione_validazione');
    conStato(traccia(9.7, 9.8), null);

    expect(idFiltrati($this, ['Select:stato_validazione' => 'percorribile']))->toBe([$percorribile->id]);
});

it('i titoli dei filtri sono tradotti', function (string $lingua, array $attesi) {
    // La CI gira con APP_LOCALE=en: la lingua va impostata qui.
    app()->setLocale($lingua);

    $nomi = collect($this->actingAs(amministratore())->getJson('/nova-api/ec-tracks/filters')->assertOk()->json())
        ->pluck('name', 'class');

    expect($nomi[LAYER_FILTER_KEY])->toBe($attesi[0])
        ->and($nomi[EcTrackRuoloFilter::class])->toBe($attesi[1])
        ->and($nomi['Select:stato_validazione'])->toBe($attesi[2]);
})->with([
    'italiano' => ['it', ['Layer', 'Ruolo', 'Stato validazione']],
    'inglese' => ['en', ['Layer', 'Role', 'Validation status']],
]);
