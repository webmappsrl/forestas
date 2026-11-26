<?php

declare(strict_types=1);

use App\Jobs\Import\CalculateNearbyTracksJob;
use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Jobs\Import\PruneStaleTrackSearchDocumentsJob;
use App\Search\TrackSearchIndex;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Wm\WmPackage\Models\EcTrack;

/**
 * Dopo il reset notturno le tracce riprendono gli id da 1, e il reindex di fine import riscrive solo
 * quelle che esistono: le voci con un id che non è più una traccia restavano nell'indice come
 * doppioni della notte prima (oc:8999). Elasticsearch si finge come in EnteSearchTest.
 */

/**
 * @param  list<int>  $indexed  id presenti nell'indice
 * @return object{deleted: list<list<int>>}
 */
function fakeTrackSearchIndex(array $indexed): object
{
    $fake = new class($indexed) extends TrackSearchIndex
    {
        /** @var list<list<int>> */
        public array $deleted = [];

        /** @param list<int> $indexed */
        public function __construct(private readonly array $indexed) {}

        public function documentIds(): array
        {
            return $this->indexed;
        }

        public function delete(array $ids): void
        {
            $this->deleted[] = $ids;
        }
    };
    app()->instance(TrackSearchIndex::class, $fake);

    return $fake;
}

it('cancella le voci che non corrispondono a nessuna traccia', function () {
    $a = EcTrack::factory()->createQuietly(['osmid' => null])->id;
    $b = EcTrack::factory()->createQuietly(['osmid' => null])->id;
    $index = fakeTrackSearchIndex([$a, $b, 9998, 9999]);

    app()->call([new PruneStaleTrackSearchDocumentsJob, 'handle']);

    expect($index->deleted)->toBe([[9998, 9999]]);
});

it('non cancella nulla se indice e tracce coincidono', function () {
    $a = EcTrack::factory()->createQuietly(['osmid' => null])->id;
    $b = EcTrack::factory()->createQuietly(['osmid' => null])->id;
    $index = fakeTrackSearchIndex([$a, $b]);

    app()->call([new PruneStaleTrackSearchDocumentsJob, 'handle']);

    expect($index->deleted)->toBe([]);
});

it('accoda la pulizia insieme al reindex di fine import', function () {
    Queue::fake();

    CalculateNearbyTracksJob::regenerateOutputs();

    Queue::assertPushedOn(ImportSardegnaSentieriTrackJob::QUEUE, PruneStaleTrackSearchDocumentsJob::class);
});

it('scrive nel log dell\'import se la pulizia fallisce', function () {
    $channel = Mockery::mock();
    $channel->shouldReceive('error')->once()->withArgs(fn (string $message) => str_contains($message, 'Elasticsearch giù'));
    Log::shouldReceive('channel')->with('import')->andReturn($channel);

    (new PruneStaleTrackSearchDocumentsJob)->failed(new RuntimeException('Elasticsearch giù'));
});
