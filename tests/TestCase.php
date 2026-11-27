<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Jobs\BuildAppPoisGeojsonJob;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Alcuni job del wm-package (es. BuildAppPoisGeojsonJob::uniqueVia())
        // richiedono la cache store 'redis' a codice fisso, ignorando
        // CACHE_STORE=array di phpunit.xml. In test la rimappiamo su array:
        // evita di dipendere dall'estensione phpredis e da un Redis raggiungibile.
        config(['cache.stores.redis' => ['driver' => 'array', 'serialize' => false]]);

        // Da oc:8959 attaccare un termine a una traccia, salvare una tassonomia o accenderla in
        // un'app rigenera file su AWS, config.json e pois.geojson. Con la coda `sync` dei test i
        // job girerebbero davvero, verso S3. Chi vuole verificarli usa il proprio Bus::fake, che
        // sostituisce questo.
        Bus::fake([
            UpdateEcTrackAwsJob::class,
            UpdateAppConfigJob::class,
            BuildAppPoisGeojsonJob::class,
            RegenerateTaxonomyWhereOutputsJob::class,
        ]);

        // I dischi remoti del package sono finti per tutta la suite: nessun test deve scrivere
        // fuori. Vale anche per i test del package lanciati da qui, che usano questo TestCase.
        // Senza, un percorso che arriva a una scrittura parla con S3 vero e fallisce con
        // «Missing required client configuration options: region» — o, dove le credenziali ci
        // sono, scrive davvero. L'elenco e' lo stesso di `Wm\WmPackage\Tests\TestCase`.
        foreach (['wmfe', 'pois', 'conf', 'mapping', 'importer', 'backups', 'wmdumps', 'well_known_registry'] as $disk) {
            Storage::fake($disk);
        }

        // Nemmeno via HTTP: una richiesta che il test non ha finto con Http::fake() fallisce
        // invece di uscire. Da oc:9001 l'import chiama Geohub per le aree geografiche, e i test
        // che lanciano l'import intero lo avrebbero chiamato davvero; il passo delle aree
        // registra l'errore e l'import prosegue.
        Http::preventStrayRequests();
    }
}
