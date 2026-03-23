<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Services\Import\SardegnaSentieriMediaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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
     * @param  list<array{url: string, autore: string, credits: string, order: int}>  $items
     */
    public function __construct(
        public readonly int $ecPoiId,
        public readonly array $items,
    ) {
        $this->onQueue('aws');
    }

    public function handle(SardegnaSentieriMediaSyncService $sync): void
    {
        $poi = EcPoi::query()->find($this->ecPoiId);
        if ($poi === null) {
            return;
        }

        $sync->syncImportedImages($poi, $this->items);
    }
}
