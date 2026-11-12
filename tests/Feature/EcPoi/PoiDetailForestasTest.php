<?php

declare(strict_types=1);

use App\Http\Controllers\Api\EcPoiDetailController;
use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\Ente;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi as WmEcPoi;
use Wm\WmPackage\Nova\Fields\ImageCredits;

/**
 * Il dettaglio del POI di Forestas (oc:8959): `api/ec/poi/{id}` risponde da una rotta di forestas
 * che carica `App\Models\EcPoi`, con nome, `taxonomies` e `properties.forestas` costruito in uscita.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
    Storage::fake('wmfe');
    Storage::fake('s3');
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
});

function detailPoi(array $properties = [], string $point = '9.05 40.499', array $name = ['it' => 'Nuraghe Serbissi']): EcPoi
{
    return EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => $name,
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => $properties,
        'geometry' => DB::raw("ST_GeomFromText('POINT Z({$point} 0)', 4326)"),
    ]));
}

function detailPoiTrack(string $wkt, string $ref = 'Z-NU-C-104'): EcTrack
{
    return EcTrack::withoutEvents(fn () => EcTrack::query()->create([
        'name' => ['it' => 'Percorso '.$ref],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => ['ref' => $ref],
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z(({$wkt}))', 4326)"),
    ]));
}

function poiPhoto(EcPoi $poi, array $customProperties = [])
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    return $poi->addMediaFromString($png)->usingFileName('foto.png')
        ->withCustomProperties($customProperties)
        ->toMediaCollection('default');
}

/**
 * La risposta del controller della rotta; che la rotta arrivi qui lo verifica il primo test.
 */
function poiDetail(EcPoi $poi): array
{
    $response = (new EcPoiDetailController)->show($poi->id);
    expect($response->getStatusCode())->toBe(200);

    return $response->getData(true);
}

it('risponde da una rotta di forestas', function () {
    $poi = detailPoi();
    $this->getJson("/api/ec/poi/{$poi->id}")->assertOk()->assertJsonPath('properties.name.it', 'Nuraghe Serbissi');
    expect(ltrim(Route::getRoutes()->getByName('default.api.ec.poi.json')->getActionName(), '\\'))
        ->toBe(EcPoiDetailController::class.'@show');
});

it('lascia al package le altre rotte del POI', function () {
    $poi = detailPoi();

    $this->get("/api/ec/poi/{$poi->id}.geojson")->assertOk();
    expect(Route::getRoutes()->getByName('default.api.ec.poi.view.geojson')->getActionName())
        ->not->toContain('EcPoiDetailController');
});

it('dà nome e chiavi del package, senza id di Drupal e layer', function () {
    $poi = detailPoi([
        'description' => ['it' => '<p>Area archeologica</p>'],
        'addr_complete' => 'Osini, nuraghe Serbissi',
        'out_source_feature_id' => '137',
        'layers' => [4],
    ]);

    $detail = poiDetail($poi);

    expect($detail['type'])->toBe('Feature')
        ->and($detail['geometry']['type'])->toBe('Point')
        ->and($detail['properties'])->toMatchArray([
            'id' => $poi->id,
            'name' => ['it' => 'Nuraghe Serbissi'],
            'addr_complete' => 'Osini, nuraghe Serbissi',
        ])
        ->and($detail['properties'])->toHaveKey('taxonomies')
        ->and($detail['properties'])->not->toHaveKeys(['out_source_feature_id', 'layers']);
});

it('costruisce forestas in uscita con chiavi in inglese e senza id di Drupal', function () {
    $poi = detailPoi(['forestas' => [
        'url' => 'https://www.sardegnasentieri.it/da-vedere/nuraghe-serbissi-osini',
        'codice' => 'C-521A Nuraghe',
        'created_at' => '2013-07-02 18:12:40',
        'updated_at' => '2025-07-28 12:33:33',
        'come_arrivare' => ['it' => 'Da Osini…'],
        'video' => ['https://www.youtube.com/watch?v=abc'],
        'allegati' => ['https://www.sardegnasentieri.it/x.pdf'],
        'poi_correlati' => ['138'],
        'zona_geografica' => ['4841'],
        'source_relations' => ['pois' => ['138'], 'tracks' => ['486']],
    ]]);

    $forestas = poiDetail($poi)['properties']['forestas'];

    expect($forestas)->toBe([
        'url' => 'https://www.sardegnasentieri.it/da-vedere/nuraghe-serbissi-osini',
        'code' => 'C-521A Nuraghe',
        'created_at' => '2013-07-02 18:12:40',
        'updated_at' => '2025-07-28 12:33:33',
        'directions' => ['it' => 'Da Osini…'],
        'videos' => [['url' => 'https://www.youtube.com/watch?v=abc', 'title' => null]],
    ])
        ->and($poi->fresh()->properties['forestas'])->toHaveKey('poi_correlati');
});

it('non rompe con come_arrivare salvato null', function () {
    $poi = detailPoi(['forestas' => ['come_arrivare' => null, 'codice' => 'X']]);

    expect(poiDetail($poi)['properties']['forestas'])->toBe(['code' => 'X']);
});

it('porta gli enti per ruolo, come nelle tracce', function () {
    $poi = detailPoi();
    $ente = Ente::create(['sardegnasentieri_id' => '31', 'slug' => 'comune-di-osini', 'name' => ['it' => 'Comune di Osini'], 'properties' => []]);
    // Il tipo con cui lo scrive l'import (SardegnaSentieriImportService).
    DB::table('enteables')->insert(['ente_id' => $ente->id, 'enteable_id' => $poi->id, 'enteable_type' => WmEcPoi::class, 'ruolo' => 'operatore']);

    expect(poiDetail($poi)['properties']['forestas']['organizations'])->toBe([
        'operator' => [['id' => $ente->id, 'slug' => 'comune-di-osini', 'name' => ['it' => 'Comune di Osini']]],
    ]);
});

it('porta i POI correlati con i dati della card', function () {
    $poi = detailPoi();
    $related = detailPoi(['addr_complete' => 'Tuones (1025 m)'], '9.06 40.5', ['it' => 'Tuones']);
    $photo = poiPhoto($related, [ImageCredits::CAPTION => 'La capanna']);
    DB::table('ec_poi_related_pois')->insert(['ec_poi_id' => $poi->id, 'related_poi_id' => $related->id]);

    $card = poiDetail($poi)['properties']['forestas']['related_pois'][0];

    expect($card)->toMatchArray([
        'id' => $related->id,
        'name' => ['it' => 'Tuones'],
        'addr_complete' => 'Tuones (1025 m)',
    ])
        ->and($card['feature_image'])->toMatchArray(['id' => $photo->id, 'caption' => 'La capanna'])
        // La quota della geometria è 0: non esce.
        ->and($card)->not->toHaveKey('elevation');
});

it('porta i percorsi collegati dal più vicino, con la card e la distanza dal POI', function () {
    $poi = detailPoi([], '9.05 40.499');
    $far = detailPoiTrack('9 40.51 0, 9.1 40.51 0', 'FAR');
    $close = detailPoiTrack('9 40.5 0, 9.1 40.5 0', 'CLOSE');
    $notLinked = detailPoiTrack('9 40.4995 0, 9.1 40.4995 0', 'NOT');
    DB::table('ec_poi_ec_track')->insert([
        ['ec_poi_id' => $poi->id, 'ec_track_id' => $far->id, 'order' => 0],
        ['ec_poi_id' => $poi->id, 'ec_track_id' => $close->id, 'order' => 1],
    ]);

    $forestas = poiDetail($poi)['properties']['forestas'];

    expect(array_column($forestas['nearby_tracks'], 'id'))->toBe([$close->id, $far->id])
        ->and($forestas['nearby_tracks_count'])->toBe(2)
        ->and($forestas['nearby_tracks'][0])->toHaveKeys(['ref', 'name', 'route_type', 'feature_image', 'distance', 'duration_forward'])
        ->and($forestas['nearby_tracks'][0]['distance_from_poi'])->toBeGreaterThan(100)->toBeLessThan(120)
        ->and(array_column($forestas['nearby_tracks'], 'id'))->not->toContain($notLinked->id);
});

it('mostra al massimo 10 percorsi, ma li conta tutti', function () {
    $poi = detailPoi();
    foreach (range(1, 12) as $i) {
        $track = detailPoiTrack('9 40.'.(500 + $i).' 0, 9.1 40.'.(500 + $i).' 0', 'T'.$i);
        DB::table('ec_poi_ec_track')->insert(['ec_poi_id' => $poi->id, 'ec_track_id' => $track->id, 'order' => $i]);
    }

    $forestas = poiDetail($poi)['properties']['forestas'];

    expect($forestas['nearby_tracks'])->toHaveCount(10)
        ->and($forestas['nearby_tracks_count'])->toBe(12);
});

it('non fa una query per ogni percorso', function () {
    $poi = detailPoi();
    $count = function (int $tracks) use ($poi) {
        DB::table('ec_poi_ec_track')->where('ec_poi_id', $poi->id)->delete();
        foreach (range(1, $tracks) as $i) {
            $track = detailPoiTrack('9 40.'.(500 + $i).' 0, 9.1 40.'.(500 + $i).' 0', 'Q'.$tracks.'-'.$i);
            DB::table('ec_poi_ec_track')->insert(['ec_poi_id' => $poi->id, 'ec_track_id' => $track->id, 'order' => $i]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        poiDetail($poi);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(5))->toBe($count(1));
});

it('lascia invariato il POI nel DB', function () {
    $poi = detailPoi(['forestas' => ['codice' => 'X', 'source_relations' => ['pois' => []]]]);

    poiDetail($poi);

    expect($poi->fresh()->properties['forestas'])->toBe(['codice' => 'X', 'source_relations' => ['pois' => []]]);
});
