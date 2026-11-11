<?php

declare(strict_types=1);

use App\Models\EcTrack;
use App\Models\Ente;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;

/**
 * `properties.forestas` nel dettaglio della traccia (oc:8959): un oggetto pubblico costruito in
 * uscita con i soli dati che il frontend usa, con chiavi in inglese. Il `properties.forestas`
 * salvato nel DB non cambia: lo usano import, Nova e job.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
    Storage::fake('wmfe');
    Storage::fake('s3');
    config(['cache.stores.redis.driver' => 'array', 'database.redis.client' => 'predis']);

    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
});

function forestasTrack(array $forestas = [], array $columns = []): EcTrack
{
    $track = Wm\WmPackage\Models\EcTrack::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'osmid' => null,
        'properties' => ['forestas' => $forestas],
        ...$columns,
    ]);

    return EcTrack::find($track->id);
}

function detailForestas(EcTrack $track): array
{
    return (array) $track->fresh()->getDetailGeojson()['properties']['forestas'];
}

it('espone i dati di forestas con chiavi in inglese e senza le chiavi interne', function () {
    $track = forestasTrack([
        'url' => 'https://www.sardegnasentieri.it/sentieri/salita-sul-monte-corrasi-b-401',
        'created_at' => '2013-07-02 18:12:40',
        'updated_at' => '2024-03-20 15:58:28',
        'info_utili' => ['it' => ['<p>Segnaletica</p>']],
        'roadbook' => ['it' => ['<p>Il percorso parte…</p>']],
        'video' => ['https://www.youtube.com/watch?v=abc'],
        // Interne o id di Drupal: non devono uscire.
        'gpx' => ['https://www.sardegnasentieri.it/x.gpx'],
        'allegati' => ['https://www.sardegnasentieri.it/x.kml'],
        'zona_geografica' => ['4848'],
        'source_id' => '1043',
        'source_relations' => ['pois' => ['1042'], 'tracks' => []],
        'skip_dem_jobs' => true,
        'anomalies' => ['x'],
        'enti_mancanti' => ['y'],
        'deleted_from_source' => false,
    ]);

    expect(collect(detailForestas($track))->except('downloads')->all())->toEqual([
        'url' => 'https://www.sardegnasentieri.it/sentieri/salita-sul-monte-corrasi-b-401',
        'created_at' => '2013-07-02 18:12:40',
        'updated_at' => '2024-03-20 15:58:28',
        'useful_info' => ['it' => ['<p>Segnaletica</p>']],
        'roadbook' => ['it' => ['<p>Il percorso parte…</p>']],
        'videos' => [['url' => 'https://www.youtube.com/watch?v=abc', 'title' => null]],
    ]);
});

it('porta codice registro, data di rilievo e inclusività dentro forestas, non piatti in properties', function () {
    $track = forestasTrack(
        ['inclusivity_independent_use' => 'Sì', 'inclusivity_assistance' => 'Su richiesta'],
        [
            'forestas_registry_code' => 'Z-NU-B-401',
            'forestas_survey_date' => '2022-08-04',
            'forestas_inclusivity_physical' => 40,
            'forestas_inclusivity_aids' => 30,
            'forestas_inclusivity_digital' => 20,
        ]
    );

    $properties = $track->fresh()->getDetailGeojson()['properties'];

    expect((array) $properties['forestas'])->toMatchArray([
        'registry_code' => 'Z-NU-B-401',
        'survey_date' => '2022-08-04',
        'inclusivity' => [
            'physical' => 40,
            'aids' => 30,
            'digital' => 20,
            'score' => 90,
            'class' => 'A1',
            'independent_use' => 'Sì',
            'assistance' => 'Su richiesta',
        ],
    ])
        ->and(array_filter(array_keys($properties), fn ($key) => str_starts_with($key, 'forestas_')))->toBe([]);
});

it('non scrive punteggio e classe se manca uno dei tre valori di inclusività', function () {
    $track = forestasTrack([], ['forestas_inclusivity_physical' => 40]);

    expect(detailForestas($track)['inclusivity'])->toEqual(['physical' => 40]);
});

it('porta allegati e video caricati con l\'indirizzo dello shard e il titolo di Drupal', function () {
    $track = forestasTrack(['video' => ['https://www.youtube.com/watch?v=abc']]);
    $pdf = $track->addMediaFromString('pdf')->usingFileName('carta.pdf')
        ->withCustomProperties(['sardegnasentieri_title' => 'Carta percorsi Baratz (anno 2012)'])
        ->toMediaCollection('attachments');
    $untitled = $track->addMediaFromString('pdf')->usingFileName('senza-titolo.pdf')->toMediaCollection('attachments');
    $video = $track->addMediaFromString('mp4')->usingFileName('percorso.mp4')
        ->withCustomProperties(['sardegnasentieri_title' => 'Il percorso'])
        ->toMediaCollection('videos');

    $forestas = detailForestas($track);

    expect($forestas['attachments'])->toEqual([
        ['url' => $pdf->getUrl(), 'title' => 'Carta percorsi Baratz (anno 2012)'],
        ['url' => $untitled->getUrl(), 'title' => null],
    ])
        ->and($forestas['videos'])->toEqual([
            ['url' => 'https://www.youtube.com/watch?v=abc', 'title' => null],
            ['url' => $video->getUrl(), 'title' => 'Il percorso'],
        ]);
});

it('una traccia senza dati di forestas ha solo i link per scaricarla', function () {
    $track = forestasTrack();

    expect(array_keys(detailForestas($track)))->toBe(['downloads']);
});

it('lascia invariato properties.forestas salvato nel DB', function () {
    $track = forestasTrack(['source_id' => '1043', 'info_utili' => ['it' => ['x']]]);

    $track->fresh()->getDetailGeojson();

    expect($track->fresh()->properties['forestas'])->toBe(['source_id' => '1043', 'info_utili' => ['it' => ['x']]]);
});

it('mette didascalia, autore e credits delle foto in forestas.image_credits, per id del media', function () {
    $track = forestasTrack();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $first = $track->addMediaFromString($png)->usingFileName('a.png')
        ->withCustomProperties([
            'sardegnasentieri_caption' => 'Il Monte Corrasi',
            'sardegnasentieri_autore' => 'Matteo Cara',
            'sardegnasentieri_credits' => 'Comunità Montana',
        ])
        ->toMediaCollection('default');
    $partial = $track->addMediaFromString($png)->usingFileName('b.png')
        ->withCustomProperties(['sardegnasentieri_autore' => '', 'sardegnasentieri_credits' => 'Sardegna Digital Library'])
        ->toMediaCollection('default');
    $bare = $track->addMediaFromString($png)->usingFileName('c.png')->toMediaCollection('default');

    // Le foto le scrive MediaResource del package, che non ha campi per questi dati: stanno in
    // forestas e il frontend li abbina per id.
    expect(detailForestas($track)['image_credits'])->toEqual([
        (string) $first->id => ['caption' => 'Il Monte Corrasi', 'author' => 'Matteo Cara', 'credits' => 'Comunità Montana'],
        (string) $partial->id => ['credits' => 'Sardegna Digital Library'],
    ])
        ->and(detailForestas($track)['image_credits'])->not->toHaveKey((string) $bare->id);
});

it('toglie dal dettaglio l\'id del nodo Drupal', function () {
    $track = forestasTrack();
    $track->forceFill(['properties' => [...$track->properties, 'sardegnasentieri_id' => '4516']])->saveQuietly();

    expect($track->fresh()->getDetailGeojson()['properties'])->not->toHaveKey('sardegnasentieri_id')
        ->and($track->fresh()->properties['sardegnasentieri_id'])->toBe('4516');
});

it('porta gli enti con tutti i loro ruoli, un elemento per ente', function () {
    $track = forestasTrack();
    $ente = Ente::create(['sardegnasentieri_id' => '29', 'name' => ['it' => 'Comunità Montana'], 'properties' => []]);
    $other = Ente::create(['sardegnasentieri_id' => '17', 'name' => ['it' => 'Comune di Oliena'], 'properties' => []]);
    foreach (['gestore', 'manutentore'] as $role) {
        $track->enti()->attach($ente->id, ['ruolo' => $role]);
    }
    $track->enti()->attach($other->id, ['ruolo' => 'operatore']);

    expect(detailForestas($track)['entities'])->toEqualCanonicalizing([
        ['id' => $ente->id, 'name' => ['it' => 'Comunità Montana'], 'roles' => ['gestore', 'manutentore']],
        ['id' => $other->id, 'name' => ['it' => 'Comune di Oliena'], 'roles' => ['operatore']],
    ]);
});

it('porta i sentieri vicini con id, ref e nome', function () {
    $track = forestasTrack();
    $near = forestasTrack();
    $near->forceFill(['name' => ['it' => 'Vicino'], 'properties' => ['ref' => 'Z-NU-T-431B']])->saveQuietly();
    DB::table('ec_track_related_tracks')->insert(['ec_track_id' => $track->id, 'related_track_id' => $near->id]);

    $nearby = detailForestas($track)['nearby_tracks'];

    expect($nearby)->toHaveCount(1)
        ->and($nearby[0])->toMatchArray(['id' => $near->id, 'ref' => 'Z-NU-T-431B'])
        ->and($nearby[0]['name']['it'])->toBe('Vicino');
});

it('porta i link per scaricare la traccia in GeoJSON, GPX e KML', function () {
    $track = forestasTrack();

    expect(detailForestas($track)['downloads'])->toBe([
        'geojson' => route('default.api.ec.track.download.geojson', ['id' => $track->id]),
        'gpx' => route('default.api.ec.track.download.gpx', ['id' => $track->id]),
        'kml' => route('default.api.ec.track.download.kml', ['id' => $track->id]),
    ]);
});
