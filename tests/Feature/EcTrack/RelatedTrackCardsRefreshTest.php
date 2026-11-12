<?php

declare(strict_types=1);

use App\Jobs\RefreshRelatedTrackCardsJob;
use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use App\Support\RelatedTrackCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Nova\Fields\ImageCredits;

/**
 * Le card dei sentieri correlati (oc:8959) copiano nel file di ogni traccia nome, codice, tipo,
 * foto, tempo e lunghezza delle tracce vicine. Quando una di queste cambia fuori dall'import,
 * si riscrivono i file delle sole tracce che la mostrano, e solo se è cambiato un dato della card.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
    Storage::fake('wmfe');
    Storage::fake('s3');
    // Sostituisce il fake di TestCase: la lista deve ripeterne i job.
    Bus::fake([
        RefreshRelatedTrackCardsJob::class,
        UpdateEcTrackAwsJob::class,
        UpdateAppConfigJob::class,
        BuildAppPoisGeojsonJob::class,
        RegenerateTaxonomyWhereOutputsJob::class,
    ]);
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);
});

function cardTrack(array $attributes = []): EcTrack
{
    $track = Wm\WmPackage\Models\EcTrack::factory()->createQuietly([
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'osmid' => null,
        'properties' => ['ref' => 'Z-NU-B-401'],
        ...$attributes,
    ]);

    return EcTrack::find($track->id);
}

/** $shown compare fra i correlati di ciascuna delle $viewers. */
function relate(EcTrack $shown, EcTrack ...$viewers): void
{
    foreach ($viewers as $viewer) {
        DB::table('ec_track_related_tracks')->insert([
            ['ec_track_id' => $viewer->id, 'related_track_id' => $shown->id],
            ['ec_track_id' => $shown->id, 'related_track_id' => $viewer->id],
        ]);
    }
}

/**
 * Il job è unico finché non parte, e con Bus::fake non parte mai: fra un passo e l'altro si libera
 * il lock, come se il job precedente fosse già partito.
 */
function releaseCardLocks(): void
{
    app('cache')->forgetDriver('redis');
}

function cardPhoto(EcTrack $track, array $customProperties = [])
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    return $track->addMediaFromString($png)->usingFileName('foto.png')
        ->withCustomProperties($customProperties)
        ->toMediaCollection('default');
}

it('riscrive i file delle sole tracce che mostrano quella modificata', function () {
    $changed = cardTrack();
    $viewer = cardTrack();
    $other = cardTrack();
    relate($changed, $viewer);

    (new RefreshRelatedTrackCardsJob($changed->id))->handle();

    Bus::assertDispatched(UpdateEcTrackAwsJob::class, 1);
    Bus::assertDispatched(UpdateEcTrackAwsJob::class, fn ($job) => $job->getEcTrack()->id === $viewer->id);
    Bus::assertNotDispatched(UpdateEcTrackAwsJob::class, fn ($job) => $job->getEcTrack()->id === $other->id);
});

it('non accoda nulla se la traccia non è fra i correlati di nessuno', function () {
    $alone = cardTrack();

    (new RefreshRelatedTrackCardsJob($alone->id))->handle();

    Bus::assertNotDispatched(UpdateEcTrackAwsJob::class);
});

it('parte quando cambia un dato della card: nome, codice, lunghezza', function () {
    $track = cardTrack();
    relate($track, cardTrack());

    $track->fresh()->update(['name' => ['it' => 'Nome nuovo']]);
    Bus::assertDispatched(RefreshRelatedTrackCardsJob::class, fn ($job) => $job->trackId === $track->id);

    Bus::fake([RefreshRelatedTrackCardsJob::class, UpdateEcTrackAwsJob::class]);
    releaseCardLocks();
    $fresh = $track->fresh();
    $fresh->update(['properties' => [...$fresh->properties, 'manual_data' => ['distance' => 12.5]]]);
    Bus::assertDispatched(RefreshRelatedTrackCardsJob::class);
});

it('non parte per un dato che la card non mostra', function () {
    $track = cardTrack();
    relate($track, cardTrack());

    $fresh = $track->fresh();
    $fresh->update(['properties' => [...$fresh->properties, 'forestas' => ['url' => 'https://x']]]);

    Bus::assertNotDispatched(RefreshRelatedTrackCardsJob::class);
});

it('alla cancellazione riscrive le tracce che la mostravano, prima che le righe spariscano', function () {
    $track = cardTrack();
    $viewer = cardTrack();
    relate($track, $viewer);

    $track->fresh()->delete();

    Bus::assertDispatched(RefreshRelatedTrackCardsJob::class, fn ($job) => $job->viewerIds === [$viewer->id]);
});

it('parte per la prima foto e per i suoi crediti, non per le altre foto', function () {
    // Un salvataggio con più modifiche accoda comunque un job solo per traccia: è il lock.
    $track = cardTrack();
    relate($track, cardTrack());

    $first = cardPhoto($track);
    Bus::assertDispatched(RefreshRelatedTrackCardsJob::class, 1);
    releaseCardLocks();

    $second = cardPhoto($track);
    $second->setCustomProperty(ImageCredits::AUTHOR, 'Altro')->save();
    Bus::assertDispatched(RefreshRelatedTrackCardsJob::class, 1);

    $first->setCustomProperty(ImageCredits::AUTHOR, 'Matteo Cara')->save();
    Bus::assertDispatched(RefreshRelatedTrackCardsJob::class, 2);
    releaseCardLocks();

    $first->delete();
    Bus::assertDispatched(RefreshRelatedTrackCardsJob::class, 3);
});

it('parte per il tipo di percorso, non per le altre tassonomie', function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $track = cardTrack();
    relate($track, cardTrack());

    $track->taxonomyTerms()->attach(TaxonomyTerm::where('identifier', 'validation-status-walkable')->value('id'));
    Bus::assertNotDispatched(RefreshRelatedTrackCardsJob::class);

    $track->taxonomyTerms()->attach(TaxonomyTerm::where('identifier', 'route-type-trail')->value('id'));
    Bus::assertDispatched(RefreshRelatedTrackCardsJob::class, fn ($job) => $job->trackId === $track->id);
});

it('durante l\'import delle foto non accoda nulla: i file li rigenera la fine dell\'import', function () {
    $track = cardTrack();
    relate($track, cardTrack());

    RelatedTrackCards::withoutRefresh(fn () => cardPhoto($track));

    Bus::assertNotDispatched(RefreshRelatedTrackCardsJob::class);
});
