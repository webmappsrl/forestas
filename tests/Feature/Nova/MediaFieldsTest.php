<?php

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Models\Ente;
use App\Models\User;
use App\Nova\EcPoi as EcPoiResource;
use App\Nova\EcTrack as EcTrackResource;
use App\Nova\Ente as EnteResource;
use App\Services\Import\SardegnaSentieriImportService;
use Ebess\AdvancedNovaMediaLibrary\Fields\Files;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Nova\Fields\ImageCredits;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Immagini, documenti e video gestibili da Nova (oc:8932).
 *
 * Si provano i campi come li monta la Resource e, dove conta l'effetto, l'endpoint vero: è lì che
 * ogni salvataggio del form passa dalla validazione dei campi media.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
    Storage::fake('wmfe');
    Storage::fake('s3');
    config(['cache.stores.redis.driver' => 'array', 'database.redis.client' => 'predis']);

    RolesAndPermissionsService::seedDatabase();
    $this->user = User::factory()->create();
    $this->user->assignRole('Administrator');
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);

    $this->track = Wm\WmPackage\Models\EcTrack::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => [],
    ]);
    $this->track = EcTrack::find($this->track->id);

    // Le immagini passano dalle conversioni: servono byte di un'immagine vera.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $this->addFile = fn ($model, string $collection, string $name) => $model
        ->addMediaFromString(str_ends_with($name, '.png') ? $png : 'contenuto')
        ->usingFileName($name)
        ->toMediaCollection($collection);

    // I campi obbligatori del form della traccia: senza, la richiesta si ferma prima dei media.
    $this->required = fn () => [
        'app' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'user' => $this->user->id,
    ];

    /** I campi media col valore letto dal modello, come li riceve la pagina (senza passare dal dettaglio, che lancia il job DEM). */
    $this->resolved = function ($model) {
        $resource = new EcTrackResource($model);

        return ($this->mediaFields)($resource)->each(fn ($f) => $f->resolve($model));
    };

    /** I campi media della Resource, per collection. */
    $this->mediaFields = fn ($resource) => collect($resource->fields(NovaRequest::create('/nova-api/x', 'GET')))
        // Su traccia e POI i campi stanno nel tab «Forestas»; su Ente, senza tab, al livello alto.
        ->flatMap(fn ($f) => $f instanceof TabsGroup
            ? collect($f->tabs)->flatMap(fn ($t) => $t->data)
            : [$f])
        ->filter(fn ($f) => $f instanceof Images)
        ->keyBy('attribute');
});

it('mostra le immagini sulla pagina del sentiero', function () {
    ($this->addFile)($this->track, 'default', 'foto.png');

    $fields = ($this->mediaFields)(new EcTrackResource($this->track));

    expect($fields['default'])->toBeInstanceOf(Images::class);

    $field = ($this->resolved)($this->track)['default'];

    expect($field->value)->toHaveCount(1)
        ->and($field->value[0]['file_name'])->toBe('foto.png');
});

it('ha un solo campo per le immagini, non quello del package in piu\'', function () {
    // Il campo `Image` di `AbstractEcResource` punta alla stessa collection `default` del campo
    // di forestas: senza il filtro le stesse foto comparivano due volte in pagina (oc:8932).
    // `keyBy('attribute')` dell'helper ne terrebbe uno solo, quindi qui si contano a mano.
    $images = collect((new EcTrackResource($this->track))->fields(NovaRequest::create('/nova-api/x', 'GET')))
        ->flatMap(fn ($f) => $f instanceof TabsGroup ? collect($f->tabs)->flatMap(fn ($t) => $t->data) : [$f])
        ->filter(fn ($f) => $f instanceof Images && $f->attribute === 'default');

    expect($images)->toHaveCount(1);
});

it('permette di leggere e correggere didascalia, autore e credits di una foto', function () {
    // Arrivano da Drupal nelle custom properties del media (oc:8932) e sono i campi del package,
    // che li porta con la foto nel dettaglio (oc:8959).
    $custom = collect(($this->mediaFields)(new EcTrackResource($this->track))['default']->meta()['customPropertiesFields'] ?? []);

    expect($custom->pluck('attribute')->all())->toBe([ImageCredits::CAPTION, ImageCredits::AUTHOR, ImageCredits::CREDITS]);
});

it('non mette la didascalia su documenti e video, che non ne hanno', function () {
    $fields = collect((new EcTrackResource($this->track))->fields(NovaRequest::create('/nova-api/x', 'GET')))
        ->flatMap(fn ($f) => $f instanceof TabsGroup ? collect($f->tabs)->flatMap(fn ($t) => $t->data) : [$f])
        ->filter(fn ($f) => $f instanceof Files && in_array($f->attribute, ['attachments', 'videos'], true));

    foreach ($fields as $field) {
        expect($field->meta()['customPropertiesFields'] ?? [])->toBe([]);
    }
});

it('mostra i documenti in un campo separato dalle immagini', function () {
    ($this->addFile)($this->track, 'default', 'foto.png');
    ($this->addFile)($this->track, 'attachments', 'scheda.pdf');

    $fields = ($this->resolved)($this->track);

    expect($fields['attachments']->value)->toHaveCount(1)
        ->and($fields['attachments']->value[0]['file_name'])->toBe('scheda.pdf')
        ->and($fields['default']->value)->toHaveCount(1)
        ->and($fields['default']->value[0]['file_name'])->toBe('foto.png');

    expect(($this->mediaFields)(new EcTrackResource($this->track))['attachments'])->toBeInstanceOf(Files::class);
});

it('mostra i video in un campo separato', function () {
    ($this->addFile)($this->track, 'videos', 'filmato.mp4');
    ($this->addFile)($this->track, 'attachments', 'scheda.pdf');

    $fields = ($this->resolved)($this->track);

    expect($fields['videos']->value)->toHaveCount(1)
        ->and($fields['videos']->value[0]['file_name'])->toBe('filmato.mp4')
        ->and($fields['attachments']->value[0]['file_name'])->toBe('scheda.pdf');

    expect(($this->mediaFields)(new EcTrackResource($this->track))['videos'])->toBeInstanceOf(Files::class);
});

it('permette di eliminare un documento senza toccare le immagini', function () {
    $photo = ($this->addFile)($this->track, 'default', 'foto.png');
    $doc = ($this->addFile)($this->track, 'attachments', 'scheda.pdf');

    // Come il browser: per ogni collection si rimandano gli id dei file che restano. Il
    // documento non c'è più, la foto sì.
    $this->actingAs($this->user)
        ->putJson("/nova-api/ec-tracks/{$this->track->id}", [
            ...($this->required)(),
            '__media__' => ['default' => [$photo->id]],
        ])
        ->assertOk();

    $track = EcTrack::find($this->track->id);
    expect($track->getMedia('attachments'))->toHaveCount(0)
        ->and($track->getMedia('default'))->toHaveCount(1)
        ->and($track->getMedia('default')->first()->id)->toBe($photo->id);
});

it('salva il form anche senza toccare i campi media', function () {
    $photo = ($this->addFile)($this->track, 'default', 'foto.png');
    $doc = ($this->addFile)($this->track, 'attachments', 'scheda.pdf');
    $video = ($this->addFile)($this->track, 'videos', 'filmato.mp4');

    // La trappola di `->rules()`: sui campi media scrive in `collectionMediaRules` (proprietà
    // protetta, non le `rules` standard di Nova), che il pacchetto applica all'intero array della
    // collection e blocca ogni salvataggio. Si legge con Closure::bind: nessuno dei tre campi, su
    // nessuna delle tre Resource, deve averne.
    $collectionRules = fn ($field) => (fn () => $this->collectionMediaRules)->call($field);

    foreach ([
        new EcTrackResource($this->track),
        new EcPoiResource(new EcPoi),
        new EnteResource(new Ente),
    ] as $resource) {
        foreach (($this->mediaFields)($resource) as $field) {
            expect($collectionRules($field))->toBe([], $field->attribute);
            expect($field->meta['existingMedia'] ?? null)->toBeTrue($field->attribute);
        }
        expect(($this->mediaFields)($resource)->keys()->all())->toBe(['default', 'attachments', 'videos']);
    }

    $this->actingAs($this->user)
        ->putJson("/nova-api/ec-tracks/{$this->track->id}", [
            ...($this->required)(),
            'name' => 'Nuovo nome',
            '__media__' => [
                'default' => [$photo->id],
                'attachments' => [$doc->id],
                'videos' => [$video->id],
            ],
        ])
        ->assertOk();

    $track = EcTrack::find($this->track->id);
    expect($track->getMedia('default'))->toHaveCount(1)
        ->and($track->getMedia('attachments'))->toHaveCount(1)
        ->and($track->getMedia('videos'))->toHaveCount(1);
});
