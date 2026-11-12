<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Bus;
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
    }
}
