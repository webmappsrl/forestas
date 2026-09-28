<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Import\ImportRegistroCatastaleJob;
use App\Services\RegistroCatastale\RegistroCatastaleImporter;
use App\Services\RegistroCatastale\RegistroCatastaleReadException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Esegue a mano un giro di RegistroCatastaleImporter (oc:8539). Nell'uso
 * normale parte da ImportRegistroCatastaleJob a fine batch di
 * `sardegnasentieri:import`, non da qui: questo comando serve per una prova
 * in locale o un rilancio manuale.
 */
class ImportRegistroCatastaleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'forestas:registro-import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Specchia il registro catastale dei sentieri dal foglio Google';

    /**
     * Execute the console command.
     */
    public function handle(RegistroCatastaleImporter $importer): int
    {
        // La stessa chiave di lock che usa il middleware WithoutOverlapping del
        // job (calcolata da lui, non ricopiata a mano: se il formato cambia in
        // una versione futura di Laravel i due lock non divergono in silenzio).
        // Un giro lanciato a mano non deve incrociarsi con quello accodato
        // dall'import: cancellano e riscrivono le stesse tabelle.
        $job = new ImportRegistroCatastaleJob;
        $lock = Cache::lock($job->middleware()[0]->getLockKey($job));

        if (! $lock->get()) {
            $this->error("Un altro giro del registro catastale e' gia' in corso (job o comando): niente da fare.");

            return self::FAILURE;
        }

        try {
            $this->info('Importazione del registro catastale in corso...');

            try {
                $result = $importer->run();
            } catch (RegistroCatastaleReadException $e) {
                $this->error("Lettura fallita: {$e->getMessage()}");

                Log::channel('import')->error("[registro-catastale] Lettura fallita: {$e->getMessage()}");

                return self::FAILURE;
            }

            $this->info('Import completato.');

            Log::channel('import')->info('[registro-catastale] Import completato.', $result->logContext());

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
