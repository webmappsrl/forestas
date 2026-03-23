<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriMediaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;

class ImportSardegnaSentieriTrackMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @param  list<array{url: string, autore: string, credits: string, order: int}>  $items
     */
    public function __construct(
        public readonly int $ecTrackId,
        public readonly array $items,
    ) {
        $this->onQueue('aws');
    }

    public function handle(SardegnaSentieriMediaSyncService $sync): void
    {
        $track = EcTrack::query()->find($this->ecTrackId);
        if ($track === null) {
            return;
        }

        $sync->syncImportedImages($track, $this->items);

        // Dopo le foto: EcTrackResource deve includere gli URL media; la chain da EcPoiEcTrackObserver
        // può aver già lanciato UpdateEcTrackAwsJob senza immagini — questo assicura un upload coerente.
        UpdateEcTrackAwsJob::dispatch($track);
    }
}
