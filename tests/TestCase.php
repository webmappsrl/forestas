<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
    }
}
