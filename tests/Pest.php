<?php

use App\Http\Clients\SardegnaSentieriClient;
use App\Services\Import\Source\SardegnaSentieriSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Un solo mock per la fonte dei dati e per il client dei file di Sardegna
 * Sentieri: i test dell'import mockano letture e download GPX sullo stesso
 * oggetto, e il service lo riceve in entrambi i ruoli (oc:8900).
 */
function fonteSardegnaSentieriMock(): SardegnaSentieriClient&SardegnaSentieriSource&MockInterface
{
    return Mockery::mock(
        SardegnaSentieriClient::class.', '.SardegnaSentieriSource::class
    );
}

/**
 * Sostituisce la copia del DB Drupal con una fonte finta che risponde con gli
 * elenchi dati: per i test del comando, che guardano cosa accoda e non cosa
 * legge (oc:8900).
 *
 * @param  array<int|string, string>  $tracks  nid ⇒ changed
 * @param  array<int|string, string>  $pois  nid ⇒ changed
 * @param  array<int|string, string>  $enti  nid ⇒ changed
 */
function fonteSardegnaSentieriFinta(array $tracks = [], array $pois = [], bool $available = true, array $enti = []): void
{
    $source = Mockery::mock(SardegnaSentieriSource::class);
    $source->shouldReceive('isAvailable')->andReturn($available);
    $source->shouldReceive('getTrackList')->andReturn($tracks);
    $source->shouldReceive('getPoiList')->andReturn($pois);
    $source->shouldReceive('getEnteList')->andReturn($enti);
    $source->shouldReceive('getTaxonomy', 'getTaxonomyWarnings')->andReturn([]);

    app()->instance(SardegnaSentieriSource::class, $source);
}
