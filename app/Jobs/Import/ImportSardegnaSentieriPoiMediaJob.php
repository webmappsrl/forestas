<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Services\Import\SardegnaSentieriMediaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\EcPoi;

class ImportSardegnaSentieriPoiMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @param  list<array{url: string, autore: string, credits: string, caption?: string, order: int}>  $items
     * @param  list<array{url: string, title: string, order: int}>  $attachments
     * @param  list<array{url: string, title: string, order: int}>  $videos
     */
    public function __construct(
        public readonly int $ecPoiId,
        public readonly array $items,
        public readonly array $attachments = [],
        public readonly array $videos = [],
    ) {
        $this->onQueue('aws');
    }

    /**
     * Mai due esecuzioni insieme sullo stesso contenuto (oc:8932).
     *
     * Il `timeout` di questo job e' 300 s, ma la coda riconsegna dopo 90 s
     * (`queue.connections.redis.retry_after`): la seconda esecuzione parte mentre la prima sta
     * ancora scaricando, e `syncOne()` riconosce i file gia' presi leggendo i media che il
     * modello ha in memoria — due esecuzioni non si vedono fra loro e scaricano entrambe. Cosi'
     * sono nate 120 righe doppie su 57 file, alcune senza la didascalia.
     *
     * Si scarta il doppione invece di rimetterlo in coda: il lavoro lo sta gia' facendo
     * l'esecuzione in corso. Il lock scade da se' poco dopo il timeout, cosi' un worker morto
     * davvero non blocca il contenuto fino al giro dopo.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::class.'-'.$this->ecPoiId))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(SardegnaSentieriMediaSyncService $sync): void
    {
        $poi = EcPoi::query()->find($this->ecPoiId);
        if ($poi === null) {
            return;
        }

        $sync->syncImportedImages($poi, $this->items);
        $sync->syncImportedFiles($poi, SardegnaSentieriMediaSyncService::ATTACHMENTS_COLLECTION, $this->attachments);
        $sync->syncImportedFiles($poi, SardegnaSentieriMediaSyncService::VIDEOS_COLLECTION, $this->videos);
    }
}
