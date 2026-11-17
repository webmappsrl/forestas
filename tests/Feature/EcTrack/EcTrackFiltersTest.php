<?php

use App\Models\EcTrack as ForestasEcTrack;
use App\Models\User;
use App\Nova\Filters\EcTrackActivityFilter;
use App\Nova\Filters\EcTrackCadastralAreaFilter;
use App\Services\Import\TaxonomyWhereLayersImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Filtri della lista Nova delle EC Tracce (oc:8806, oc:8825, oc:8875, oc:8988): un filtro per
 * tassonomia sui termini del contenuto (stato di validazione, percorribilità, tipo percorso,
 * disabilità accolte, avvertenze), le attività e le aree catastali, a scelta multipla in OR.
 * Niente filtro per layer, Ruolo, App, Include/Exclude per id né difficoltà di accessibilità.
 *
 * Le aree sono tre quadrati affiancati: B (lon 9-10), C (10-11), G (11-12), lat 40-41.
 */
uses(RefreshDatabase::class);

const VALIDATION_KEY = 'taxonomy_term_validation-status';
const WALKABILITY_KEY = 'taxonomy_term_walkability-status';
const ROUTE_TYPE_KEY = 'taxonomy_term_route-type';
const DISABILITIES_KEY = 'taxonomy_term_inclusivity-disabilities';
const WARNINGS_KEY = 'taxonomy_term_warnings';

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

it('il filtro aree catastali elenca le aree ordinate per nome', function () {
    $areaG = areaCatastale('Area G', 11);
    $areaB = areaCatastale('Area B', 9);
    $areaC = areaCatastale('Area C', 10);
    taxonomyWhereConGeometria('Nuoro', ['source' => 'osmfeatures'], quadrato(30));

    $filtro = definizioneFiltro($this, EcTrackCadastralAreaFilter::class);

    // Selettore di Choice (oc:8988): id in stringa, come vuole la ricerca del selettore.
    expect($filtro['name'])->toBe(__('Cadastral areas'))
        ->and($filtro['component'])->toBe('choice-filter')
        ->and($filtro['multiple'])->toBeTrue()
        ->and($filtro['currentValue'])->toBe([])
        ->and($filtro['searchPlaceholder'])->toBeNull()
        ->and($filtro['options'])->toBe([
            ['label' => 'Area B', 'value' => (string) $areaB->id],
            ['label' => 'Area C', 'value' => (string) $areaC->id],
            ['label' => 'Area G', 'value' => (string) $areaG->id],
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

/**
 * @return list<string>
 */
function chiaviFiltri($test): array
{
    return collect($test->actingAs(amministratore())->getJson('/nova-api/ec-tracks/filters')->assertOk()->json())
        ->pluck('class')->all();
}

function conEnte(EcTrack $traccia, string $ruolo): int
{
    $enteId = DB::table('entes')->insertGetId([
        // sardegnasentieri_id è unique: un valore derivato evita collisioni fra chiamate.
        'sardegnasentieri_id' => "test-{$traccia->id}-{$ruolo}",
        'name' => json_encode(['it' => 'Ente '.$ruolo]),
    ]);
    // Come l'import: enteable_type è il model di forestas (SardegnaSentieriImportService).
    DB::table('enteables')->insert([
        'ente_id' => $enteId,
        'enteable_id' => $traccia->id,
        'enteable_type' => ForestasEcTrack::class,
        'ruolo' => $ruolo,
    ]);

    return $enteId;
}

/**
 * I termini delle tassonomie, come in produzione: li crea il comando di allineamento, la
 * percorribilità la installa la migration del dominio Catasto.
 */
function configuraTermini(): void
{
    expect(Artisan::call('sardegnasentieri:configure-taxonomy-terms'))->toBe(0);
}

/** Collega la traccia ai termini, per identifier, come fa l'import. */
function conTermini(EcTrack $traccia, string ...$identifiers): EcTrack
{
    foreach ($identifiers as $identifier) {
        $termine = TaxonomyTerm::query()->where('identifier', $identifier)->firstOrFail();
        DB::table('taxonomy_termables')->insert([
            'taxonomy_term_id' => $termine->id,
            'termable_type' => ForestasEcTrack::class,
            'termable_id' => $traccia->id,
            'parent_id' => $termine->parent_id,
        ]);
    }

    return $traccia;
}

/** L'attività, come la crea l'import nella tabella storica `taxonomy_activities`. */
function attivita(string $identifier, string $nome): TaxonomyActivity
{
    return TaxonomyActivity::create(['identifier' => $identifier, 'name' => ['it' => $nome]]);
}

function conAttivita(EcTrack $traccia, TaxonomyActivity ...$attivita): EcTrack
{
    $traccia->taxonomyActivities()->attach(collect($attivita)->pluck('id')->all());

    return $traccia;
}

/** Un termine delle avvertenze, come lo crea l'import sotto la famiglia `warnings`. */
function avvertenza(string $identifier, string $nome): TaxonomyTerm
{
    $famiglia = TaxonomyTerm::query()->taxonomies()->where('identifier', 'warnings')->firstOrFail();

    return TaxonomyTerm::create(['parent_id' => $famiglia->id, 'identifier' => $identifier, 'name' => ['it' => $nome]]);
}

it('tipo percorso e area si combinano in AND', function () {
    configuraTermini();
    $areaB = areaCatastale('Area B', 9);
    $sentieroInB = conTermini(traccia(9.2, 9.4), 'route-type-trail');
    conTermini(traccia(9.5, 9.6), 'route-type-walk');
    conTermini(traccia(20, 20.5), 'route-type-trail');
    calcolaAree();

    expect(idFiltrati($this, [
        ROUTE_TYPE_KEY => ['route-type-trail'],
        EcTrackCadastralAreaFilter::class => scelte($areaB),
    ]))->toBe([$sentieroInB->id]);
});

it('più tipi di percorso scelti valgono in OR e si combinano in AND con le aree', function () {
    configuraTermini();
    $areaB = areaCatastale('Area B', 9);
    $camminoInB = conTermini(traccia(9.2, 9.4), 'route-type-walk');
    $sentieroInB = conTermini(traccia(9.3, 9.5), 'route-type-trail');
    conTermini(traccia(9.5, 9.6), 'route-type-itinerary');
    conTermini(traccia(10.2, 10.4), 'route-type-walk');
    calcolaAree();

    expect(idFiltrati($this, [
        ROUTE_TYPE_KEY => ['route-type-walk', 'route-type-trail'],
        EcTrackCadastralAreaFilter::class => scelte($areaB),
    ]))->toEqualCanonicalizing([$camminoInB->id, $sentieroInB->id]);
});

it('un link salvato con gli id interi del vecchio filtro delle aree continua a filtrare', function () {
    $areaB = areaCatastale('Area B', 9);
    areaCatastale('Area C', 10);
    $dentroB = traccia(9.2, 9.4);
    traccia(10.2, 10.4);
    calcolaAree();

    // Il vecchio filtro outline metteva nell'URL gli id come numeri, con la stessa chiave.
    expect(idFiltrati($this, [EcTrackCadastralAreaFilter::class => [$areaB->id]]))->toBe([$dentroB->id]);
});

it('il menu dei filtri ha i filtri delle tassonomie e le aree catastali, e nient altro', function () {
    // Elenco esatto, non l'assenza di chiavi note: un filtro che ricompare con un'altra chiave
    // (Layer, App, Include/Exclude per id o uno nuovo del package) fa fallire il test.
    expect(chiaviFiltri($this))->toEqualCanonicalizing([
        VALIDATION_KEY,
        WALKABILITY_KEY,
        ROUTE_TYPE_KEY,
        EcTrackActivityFilter::class,
        DISABILITIES_KEY,
        WARNINGS_KEY,
        EcTrackCadastralAreaFilter::class,
    ]);
});

it('a dominio Catasto spento il filtro della percorribilità non c è', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);

    expect(chiaviFiltri($this))->not->toContain(WALKABILITY_KEY)
        ->and(chiaviFiltri($this))->toContain(VALIDATION_KEY);
});

it('la percorribilità usa l identifier della config del dominio', function () {
    config(['wm-package.features.trail_registry.walkability_taxonomy' => 'altra-percorribilita']);

    expect(chiaviFiltri($this))->toContain('taxonomy_term_altra-percorribilita')
        ->and(chiaviFiltri($this))->not->toContain(WALKABILITY_KEY);
});

it('il filtro Stato validazione restituisce le tracce con quel valore', function () {
    // Uno dei cinque stati della pratica catastale: «Percorribile» e «Non percorribile» sono
    // usciti da questa famiglia e vivono in `walkability-status` (oc:8989).
    configuraTermini();
    $validata = conTermini(traccia(9.2, 9.4), 'validation-status-validated');
    conTermini(traccia(9.5, 9.6), 'validation-status-under-review');
    traccia(9.7, 9.8);

    expect(idFiltrati($this, [VALIDATION_KEY => 'validation-status-validated']))->toBe([$validata->id]);
});

it('il filtro Percorribilità restituisce le tracce con quel valore', function () {
    configuraTermini();
    $percorribile = conTermini(traccia(9.2, 9.4), 'walkability-status-walkable');
    conTermini(traccia(9.5, 9.6), 'walkability-status-not-walkable');
    traccia(9.7, 9.8);

    expect(idFiltrati($this, [WALKABILITY_KEY => 'walkability-status-walkable']))->toBe([$percorribile->id]);
});

it('con i filtri tassonomici si ricostruiscono i quattro layer di tipologia', function () {
    configuraTermini();
    $sentiero = conTermini(traccia(9.2, 9.4), 'route-type-trail');
    $itinerario = conTermini(traccia(9.5, 9.6), 'route-type-itinerary');
    $cammino = conTermini(traccia(9.7, 9.8), 'route-type-walk');
    $perTutti = attivita('accessible-trails', 'Sentieri (accessibili) per tutti');
    $accessibileSentiero = conAttivita(conTermini(traccia(10.1, 10.2), 'route-type-trail'), $perTutti);
    $accessibileCammino = conAttivita(conTermini(traccia(10.3, 10.4), 'route-type-walk'), $perTutti);

    // Sentieri, Itinerari e Cammini: il tipo percorso. Sentieri Accessibili: l'attività
    // «Sentieri (accessibili) per tutti», ora che il filtro della difficoltà di accessibilità
    // non c'è più (docs/knowledge/8806-filtro-ec-tracce.md).
    expect(idFiltrati($this, [ROUTE_TYPE_KEY => ['route-type-trail']]))->toEqualCanonicalizing([$sentiero->id, $accessibileSentiero->id])
        ->and(idFiltrati($this, [ROUTE_TYPE_KEY => ['route-type-itinerary']]))->toBe([$itinerario->id])
        ->and(idFiltrati($this, [ROUTE_TYPE_KEY => ['route-type-walk']]))->toEqualCanonicalizing([$cammino->id, $accessibileCammino->id])
        ->and(idFiltrati($this, [EcTrackActivityFilter::class => ['accessible-trails']]))
        ->toEqualCanonicalizing([$accessibileSentiero->id, $accessibileCammino->id]);
});

it('disabilità accolte e tipo percorso si combinano in AND', function () {
    configuraTermini();
    $entrambi = conTermini(traccia(9.2, 9.4), 'route-type-trail', 'inclusivity-disabilities-physical');
    conTermini(traccia(9.5, 9.6), 'route-type-trail');
    conTermini(traccia(9.7, 9.8), 'route-type-walk', 'inclusivity-disabilities-physical');

    expect(idFiltrati($this, [
        ROUTE_TYPE_KEY => ['route-type-trail'],
        DISABILITIES_KEY => ['inclusivity-disabilities-physical'],
    ]))->toBe([$entrambi->id]);
});

it('i filtri tassonomici hanno il selettore di Choice nella modalità giusta', function () {
    configuraTermini();
    $filtri = collect($this->actingAs(amministratore())->getJson('/nova-api/ec-tracks/filters')->assertOk()->json())
        ->keyBy('class');

    expect($filtri[VALIDATION_KEY]['component'])->toBe('choice-filter')
        ->and($filtri[VALIDATION_KEY]['multiple'])->toBeFalse()
        ->and($filtri[WALKABILITY_KEY]['multiple'])->toBeFalse()
        ->and($filtri[ROUTE_TYPE_KEY]['multiple'])->toBeTrue()
        ->and($filtri[DISABILITIES_KEY]['multiple'])->toBeTrue()
        ->and($filtri[WARNINGS_KEY]['multiple'])->toBeTrue()
        ->and($filtri[EcTrackActivityFilter::class]['component'])->toBe('choice-filter')
        ->and($filtri[EcTrackActivityFilter::class]['multiple'])->toBeTrue()
        ->and(collect($filtri[ROUTE_TYPE_KEY]['options'])->pluck('value')->all())
        ->toBe(['route-type-trail', 'route-type-itinerary', 'route-type-walk']);
});

it('i titoli dei filtri sono i nomi delle tassonomie', function () {
    configuraTermini();
    app()->setLocale('it');

    $nomi = collect($this->actingAs(amministratore())->getJson('/nova-api/ec-tracks/filters')->assertOk()->json())
        ->pluck('name', 'class');

    expect($nomi[VALIDATION_KEY])->toBe('Stato validazione')
        ->and($nomi[ROUTE_TYPE_KEY])->toBe('Tipo percorso')
        ->and($nomi[DISABILITIES_KEY])->toBe('Disabilità accolte')
        ->and($nomi[WARNINGS_KEY])->toBe('Avvertenze')
        ->and($nomi[EcTrackActivityFilter::class])->toBe('Attività')
        ->and($nomi[EcTrackCadastralAreaFilter::class])->toBe('Aree Catastali');
});

it('lo stesso menu filtra le tracce di un ente nella sua detail view', function () {
    configuraTermini();
    $gestitaSentiero = conTermini(traccia(9.2, 9.4), 'route-type-trail');
    $gestitaCammino = conTermini(traccia(9.5, 9.6), 'route-type-walk');
    conTermini(traccia(9.7, 9.8), 'route-type-trail');
    $enteId = conEnte($gestitaSentiero, 'gestore');
    DB::table('enteables')->insert([
        'ente_id' => $enteId,
        'enteable_id' => $gestitaCammino->id,
        'enteable_type' => ForestasEcTrack::class,
        'ruolo' => 'gestore',
    ]);
    $via = "viaResource=entes&viaResourceId={$enteId}&viaRelationship=ecTracksGestore&relationshipType=morphToMany";
    $filtri = base64_encode(json_encode([[ROUTE_TYPE_KEY => ['route-type-trail']]]));

    $chiavi = collect($this->actingAs(amministratore())->getJson("/nova-api/ec-tracks/filters?{$via}")->assertOk()->json())
        ->pluck('class')->all();
    $ids = $this->actingAs(amministratore())
        ->getJson("/nova-api/ec-tracks?{$via}&perPage=100&filters={$filtri}")
        ->assertOk()
        ->json('resources.*.id.value');

    expect($chiavi)->toContain(ROUTE_TYPE_KEY)
        ->and($ids)->toBe([$gestitaSentiero->id]);
});

it('il filtro Attività elenca le attività con l identifier come valore', function () {
    attivita('mtb', 'Mountain Bike');
    attivita('hiking', 'Alte vie');
    app()->setLocale('it');

    $filtro = definizioneFiltro($this, EcTrackActivityFilter::class);

    expect($filtro['currentValue'])->toBe([])
        ->and($filtro['options'])->toBe([
            ['label' => 'Mountain Bike', 'value' => 'mtb'],
            ['label' => 'Alte vie', 'value' => 'hiking'],
        ]);
});

it('più attività scelte valgono in OR e si combinano in AND con il tipo percorso', function () {
    configuraTermini();
    $mtb = attivita('mtb', 'Mountain Bike');
    $cavallo = attivita('horse', 'Turismo equestre');
    $nordic = attivita('nordic-walking', 'Nordic Walking');
    $sentieroMtb = conAttivita(conTermini(traccia(9.2, 9.4), 'route-type-trail'), $mtb);
    $sentieroCavallo = conAttivita(conTermini(traccia(9.5, 9.6), 'route-type-trail'), $cavallo, $nordic);
    conAttivita(conTermini(traccia(9.7, 9.8), 'route-type-walk'), $mtb);
    conAttivita(conTermini(traccia(9.9, 9.95), 'route-type-trail'), $nordic);

    expect(idFiltrati($this, [EcTrackActivityFilter::class => ['mtb', 'horse']]))
        ->toHaveCount(3)
        ->and(idFiltrati($this, [
            EcTrackActivityFilter::class => ['mtb', 'horse'],
            ROUTE_TYPE_KEY => ['route-type-trail'],
        ]))->toEqualCanonicalizing([$sentieroMtb->id, $sentieroCavallo->id]);
});

it('un attività che non esiste non allarga i risultati', function () {
    attivita('mtb', 'Mountain Bike');
    traccia(9.2, 9.4);

    expect(idFiltrati($this, [EcTrackActivityFilter::class => ['attivita-tolta']]))->toBe([]);
});

it('il filtro Avvertenze restituisce le tracce con almeno una delle avvertenze scelte', function () {
    configuraTermini();
    avvertenza('sardegnasentieri:warning:1', 'Tratti esposti');
    avvertenza('sardegnasentieri:warning:2', 'Assenza di acqua');
    avvertenza('sardegnasentieri:warning:3', 'Guadi');
    $esposta = conTermini(traccia(9.2, 9.4), 'sardegnasentieri:warning:1');
    $senzaAcqua = conTermini(traccia(9.5, 9.6), 'sardegnasentieri:warning:2');
    conTermini(traccia(9.7, 9.8), 'sardegnasentieri:warning:3');
    traccia(9.9, 9.95);

    expect(idFiltrati($this, [WARNINGS_KEY => ['sardegnasentieri:warning:1', 'sardegnasentieri:warning:2']]))
        ->toEqualCanonicalizing([$esposta->id, $senzaAcqua->id]);
});
