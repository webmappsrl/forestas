<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Wm\WmPackage\Models\EcTrack;

/**
 * L'elenco pubblico degli avvisi dei sentieri (oc:8981), per il sito WordPress.
 */
uses(RefreshDatabase::class);

function noticeTrack(array $properties, ?array $name = null): EcTrack
{
    return EcTrack::factory()->createQuietly(array_filter([
        'name' => $name,
        'properties' => $properties,
    ], fn ($v) => $v !== null));
}

it('is public', function () {
    $this->getJson('/api/v1/avvisi')->assertOk();
});

it('returns the agreed shape', function () {
    $track = noticeTrack([
        'ref' => 'T 521',
        'not_accessible' => true,
        'not_accessible_message' => ['it' => 'Frana al km 3', 'en' => 'Landslide at km 3'],
        'not_accessible_updated_at' => '2026-11-10T09:00:00+01:00',
    ], ['it' => 'Sentiero uno', 'en' => 'Trail one']);

    $json = $this->getJson('/api/v1/avvisi')->assertOk()->json();

    expect($json['generated_at'])->toBeString()
        ->and($json['data'])->toHaveCount(1)
        ->and(array_keys($json['data'][0]))->toBe(['track_id', 'ref', 'name', 'message', 'published_at'])
        ->and($json['data'][0]['track_id'])->toBe($track->id)
        ->and($json['data'][0]['ref'])->toBe('T 521')
        ->and($json['data'][0]['name'])->toEqualCanonicalizing(['it' => 'Sentiero uno', 'en' => 'Trail one'])
        // jsonb riordina le chiavi: conta il contenuto, non l'ordine.
        ->and($json['data'][0]['message'])->toEqualCanonicalizing(['it' => 'Frana al km 3', 'en' => 'Landslide at km 3'])
        ->and($json['data'][0]['published_at'])->toBe('2026-11-10T09:00:00+01:00');
});

it('orders by date desc with undated last', function () {
    $message = ['it' => 'Avviso'];
    noticeTrack(['ref' => 'A', 'not_accessible' => true, 'not_accessible_message' => $message, 'not_accessible_updated_at' => '2026-11-10T09:00:00+01:00']);
    noticeTrack(['ref' => 'B', 'not_accessible' => true, 'not_accessible_message' => $message]);
    noticeTrack(['ref' => 'C', 'not_accessible' => true, 'not_accessible_message' => $message, 'not_accessible_updated_at' => '2026-11-15T09:00:00+01:00']);

    $refs = array_column($this->getJson('/api/v1/avvisi')->json('data'), 'ref');

    expect($refs)->toBe(['C', 'A', 'B']);
});

it('excludes switched off, empty message, english only, deleted from source', function () {
    noticeTrack(['ref' => 'OFF', 'not_accessible' => false, 'not_accessible_message' => ['it' => 'x']]);
    noticeTrack(['ref' => 'EMPTY', 'not_accessible' => true, 'not_accessible_message' => ['it' => '  ']]);
    noticeTrack(['ref' => 'EN', 'not_accessible' => true, 'not_accessible_message' => ['en' => 'Only english']]);
    noticeTrack(['ref' => 'DEL', 'not_accessible' => true, 'not_accessible_message' => ['it' => 'x'], 'forestas' => ['deleted_from_source' => true]]);

    expect($this->getJson('/api/v1/avvisi')->json('data'))->toBe([]);
});

it('wraps a plain string message as italian', function () {
    noticeTrack(['ref' => 'P', 'not_accessible' => true, 'not_accessible_message' => 'Frana']);

    expect($this->getJson('/api/v1/avvisi')->json('data.0.message'))->toBe(['it' => 'Frana']);
});

it('trims the message and drops empty locales', function () {
    noticeTrack(['ref' => 'T', 'not_accessible' => true, 'not_accessible_message' => ['it' => ' x ', 'en' => null, 'fr' => '']]);

    expect($this->getJson('/api/v1/avvisi')->json('data.0.message'))->toBe(['it' => 'x']);
});

it('breaks date ties by track id descending', function () {
    $message = ['it' => 'Avviso'];
    $date = '2026-11-10T09:00:00+01:00';
    noticeTrack(['ref' => 'A', 'not_accessible' => true, 'not_accessible_message' => $message, 'not_accessible_updated_at' => $date]);
    noticeTrack(['ref' => 'B', 'not_accessible' => true, 'not_accessible_message' => $message, 'not_accessible_updated_at' => $date]);
    noticeTrack(['ref' => 'C', 'not_accessible' => true, 'not_accessible_message' => $message]);
    noticeTrack(['ref' => 'D', 'not_accessible' => true, 'not_accessible_message' => $message]);

    $refs = array_column($this->getJson('/api/v1/avvisi')->json('data'), 'ref');

    expect($refs)->toBe(['B', 'A', 'D', 'C']);
});
