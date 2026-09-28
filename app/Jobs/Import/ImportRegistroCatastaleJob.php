<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Services\RegistroCatastale\RegistroCatastaleImporter;
use App\Services\RegistroCatastale\RegistroCatastaleReadException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Specchia il registro catastale (oc:8539) alla fine del batch di import da
 * Sardegna Sentieri: gira sulla stessa coda dedicata e nello stesso
 * momento del ricalcolo delle anomalie del Catasto, per lo stesso motivo
 * (docs/knowledge/import-asincrono-e-anomalie.md).
 */
class ImportRegistroCatastaleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Chiave del lock contro i giri sovrapposti: una sola, i giri sono tutti uguali. */
    public const OVERLAP_KEY = 'registro-catastale';

    /**
     * The number of seconds the job can run before timing out.
     *
     * Sotto il `timeout` del supervisor `supervisor-sardegnasentieri-import`
     * (360 in config/horizon.php): se il job lo superasse, il worker verrebbe
     * ucciso dal supervisor prima che il job possa fallire in modo pulito.
     */
    public int $timeout = 300;

    /**
     * The number of times the job may be attempted.
     *
     * Un solo ritentativo: un foglio irraggiungibile non risponde meglio un
     * minuto dopo, e la lettura precedente resta comunque intatta finche'
     * l'importer non ne legge una nuova per intero (vedi
     * RegistroCatastaleImporter).
     */
    public int $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue(ImportSardegnaSentieriTrackJob::QUEUE);
    }

    /**
     * Un giro alla volta: il job parte a fine batch a ogni import orario, e
     * un giro lento (foglio che risponde piano) non deve incrociarsi col
     * successivo — cancellano e riscrivono le stesse tabelle. Il giro che
     * trova il lock occupato si scarta invece di rimettersi in coda: il
     * prossimo import ne accoda comunque un altro. Il lock scade da solo
     * poco dopo il `timeout`, cosi' un worker morto non lo tiene per sempre.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::OVERLAP_KEY))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(RegistroCatastaleImporter $importer): void
    {
        try {
            $result = $importer->run();
        } catch (RegistroCatastaleReadException $e) {
            // Nessun rilancio: un foglio non scaricabile non lo diventa a un
            // secondo tentativo nella stessa ora, e il mirror del giro
            // precedente resta intatto (l'eccezione arriva prima di
            // qualunque scrittura).
            Log::channel('import')->error(
                "[registro-catastale] Lettura fallita: {$e->getMessage()}"
            );

            return;
        }

        Log::channel('import')->info('[registro-catastale] Import completato.', $result->logContext());
    }
}
