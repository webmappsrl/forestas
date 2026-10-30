<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Services\Import\SardegnaSentieriImportService;
use App\Services\Import\Source\DrupalDumpLock;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Importa o aggiorna un ente da Sardegna Sentieri (oc:8513).
 *
 * Gira in un batch suo, prima di quello di POI e sentieri: i loro job
 * collegano solo enti che esistono gia'. Coda, tentativi e attesa durante la
 * ricarica della copia sono quelli di ImportSardegnaSentieriPoiJob.
 */
class ImportSardegnaSentieriEnteJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    /**
     * @param  string  $changed  il `changed` di Drupal, come nella lista degli enti
     */
    public function __construct(
        public readonly int $externalId,
        public readonly string $changed,
    ) {
        $this->onQueue(ImportSardegnaSentieriPoiJob::QUEUE);
    }

    public function handle(SardegnaSentieriImportService $service): void
    {
        // La copia si sta ricaricando: si riprova fra dieci minuti (oc:8900).
        if (DrupalDumpLock::isHeld()) {
            $this->release(600);

            return;
        }

        $service->importOrUpdateEnte((string) $this->externalId, $this->changed);
    }
}
