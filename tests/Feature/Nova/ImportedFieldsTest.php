<?php

declare(strict_types=1);

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\Ente;
use App\Models\User;
use App\Nova\EcPoi as EcPoiResource;
use App\Nova\EcTrack as EcTrackResource;
use App\Nova\Ente as EnteResource;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * I dati portati da Drupal che si leggono in Nova (oc:8932): senza un campo il dato c'è nel
 * database ma la redazione non lo vede, ed è come non averlo importato.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
    Storage::fake('wmfe');
    Storage::fake('s3');
    config(['cache.stores.redis.driver' => 'array', 'database.redis.client' => 'predis']);

    RolesAndPermissionsService::seedDatabase();
    User::factory()->create()->assignRole('Administrator');
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);

    /** I campi della Resource, tab compresi, per attributo. */
    $this->fieldsOf = fn ($resource) => collect($resource->fields(NovaRequest::create('/nova-api/x', 'GET')))
        ->flatMap(fn ($f) => $f instanceof TabsGroup ? collect($f->tabs)->flatMap(fn ($t) => $t->data) : [$f])
        ->keyBy('attribute');
});

it('mostra sul punto di interesse la data di creazione in Drupal, come sulla traccia', function () {
    // Le factory stanno nel package: il modello dello shard si rilegge per id, come negli
    // altri test Nova di forestas.
    $created = Wm\WmPackage\Models\EcPoi::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => ['forestas' => ['created_at' => '2014-05-09 11:53:14', 'updated_at' => '2026-07-09 10:46:50']],
    ]);
    $poi = EcPoi::find($created->id);

    $fields = ($this->fieldsOf)(new EcPoiResource($poi));
    $field = $fields['properties->forestas->created_at'] ?? null;

    expect($field)->not->toBeNull();

    $field->resolve($poi);
    expect($field->value)->toBe('2014-05-09 11:53:14');
});

it('mostra i collegamenti dell\'ente come tabella chiave-valore, in sola lettura', function () {
    // `Ente` e' un modello di forestas e non ha factory: le colonne obbligatorie sono due.
    $ente = Ente::create([
        'sardegnasentieri_id' => '999',
        'name' => ['it' => 'Ente di prova'],
        'properties' => ['related_url' => ['Sito ufficiale' => 'https://example.it']],
    ]);

    $field = ($this->fieldsOf)(new EnteResource($ente))['properties->related_url'] ?? null;

    expect($field)->toBeInstanceOf(KeyValue::class);

    $field->resolve($ente);
    expect($field->value)->toBe(['Sito ufficiale' => 'https://example.it'])
        ->and($field->jsonSerialize()['readonly'])->toBeTrue();
});

it('mostra sul percorso gli enti collegati, con il ruolo, senza poterli cambiare', function () {
    // In Drupal e' la sezione «Contatti a Guide, Gestori ed Enti»: il dato esisteva gia' nella
    // pivot `enteables`, ma si vedeva solo aprendo l'ente (oc:8932).
    $created = Wm\WmPackage\Models\EcTrack::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'osmid' => null,
    ]);
    $track = EcTrack::find($created->id);
    $ente = Ente::create(['sardegnasentieri_id' => '998', 'name' => ['it' => 'Fondazione di prova']]);
    $track->enti()->attach($ente->id, ['ruolo' => 'operatore']);

    $resource = new EcTrackResource($track);
    $request = NovaRequest::create('/nova-api/ec-tracks/'.$track->id, 'GET');

    expect(($this->fieldsOf)($resource)->has('enti'))->toBeTrue()
        ->and($resource->authorizedToAttachAny($request, new Ente))->toBeFalse()
        ->and(DB::table('enteables')->where('enteable_id', $track->id)->value('ruolo'))->toBe('operatore');
});
