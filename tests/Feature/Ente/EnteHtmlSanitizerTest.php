<?php

declare(strict_types=1);

use App\Models\Ente;
use App\Support\EnteHtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Descrizione e contatti degli enti in HTML filtrato con una whitelist (oc:8965): il JSON degli
 * enti va stampato così com'è da WordPress, qualunque sia la strada da cui il testo è arrivato.
 */
uses(RefreshDatabase::class);

it('tiene la formattazione ammessa', function () {
    $html = '<h4>Titolo</h4><p>Testo <a href="https://x.it">link</a> <em>c</em> <strong>g</strong><br /></p><ul><li>a</li></ul><ol><li>b</li></ol>';

    expect(EnteHtmlSanitizer::clean($html))->toBe($html);
});

it('toglie tag e attributi non ammessi', function () {
    $html = '<p style="color:red" onclick="x()">a</p><script>alert(1)</script><iframe src="https://x"></iframe><a href="javascript:alert(1)" target="_blank">b</a>';

    expect(EnteHtmlSanitizer::clean($html))->toBe('<p>a</p>b');
});

it('toglie il tag ai link relativi e tiene il testo', function () {
    // Un link a Drupal (`/node/123`) su WordPress porterebbe a una pagina sbagliata: HTMLPurifier
    // filtra solo gli schemi e i relativi li lascerebbe passare.
    $html = '<p>Vedi <a href="/node/123">la scheda</a>, <a href="enti/tepilora">questa</a> e <a href="https://www.parcoditepilora.it">il parco</a> o <a href="mailto:info@x.it">scrivi</a></p>';

    expect(EnteHtmlSanitizer::clean($html))
        ->toBe('<p>Vedi la scheda, questa e <a href="https://www.parcoditepilora.it">il parco</a> o <a href="mailto:info@x.it">scrivi</a></p>');
});

it('restituisce null per un testo vuoto', function (?string $html) {
    expect(EnteHtmlSanitizer::clean($html))->toBeNull();
})->with([
    'null' => [null],
    'stringa vuota' => [''],
    'solo tag tolti' => ['<script>alert(1)</script>'],
    'paragrafo vuoto del Tiptap' => ['<p></p>'],
    'solo spazi non separabili' => ['<p>&nbsp;</p>'],
]);

it('filtra la descrizione scritta per una lingua', function () {
    $ente = Ente::create(['sardegnasentieri_id' => '13', 'name' => ['it' => 'Oasi Tepilora'], 'properties' => []]);
    $ente->setTranslation('description', 'it', '<p onclick="x()">a</p><script>alert(1)</script>');
    $ente->save();

    expect($ente->fresh()->getTranslation('description', 'it'))->toBe('<p>a</p>');
});

it('filtra i contatti scritti con fill', function () {
    $ente = Ente::create([
        'sardegnasentieri_id' => '13',
        'name' => ['it' => 'Oasi Tepilora'],
        'contatti' => ['it' => '<p style="x">Sede</p>', 'en' => '<p><img src="x" />Sede</p>'],
        'properties' => [],
    ]);

    $contacts = $ente->fresh()->getTranslations('contatti');
    ksort($contacts);

    expect($contacts)->toBe(['en' => '<p>Sede</p>', 'it' => '<p>Sede</p>']);
});
