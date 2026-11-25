<?php

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Validator;
use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

/**
 * Lo schema del corpo della creazione, dal documento OpenAPI generato davvero.
 *
 * @return array<string, mixed>
 */
function corpoDellaCreazione(): array
{
    $generator = app(Generator::class);
    $documento = $generator->generate(Scramble::getGeneratorConfig('default'))->spec();

    return $documento['paths']['/api/v1/sus/trail-applications']['post']['requestBody']['content']['multipart/form-data']['schema'];
}

// Rete di sicurezza: se lo schema esige un campo obbligatorio che nessuno ha nominato nella
// pagina delle API, chi costruisce la maschera lo scopre da un 422. Il controllo e' una
// ricerca del nome nel testo, quindi e' largo: `from` e `to` sono parole comuni e passano
// sempre, e un campo citato soltanto in un esempio `curl` e' gia' "documentato".
// La verifica puntuale del campo di oc:9038 e' il test successivo.
it('documenta ogni campo obbligatorio del canale SUS', function () {
    $prose = (string) config('scramble.info.description');

    $missing = array_values(array_filter(
        app(ApplicationSchema::class)->requiredFieldNames(),
        fn (string $field) => ! str_contains($prose, $field),
    ));

    expect($missing)->toBe([]);
});

// Pinna la sezione dell'attivita' primaria, non l'esempio `curl`: cancellata la sezione, il
// test cade anche se la riga `-F primary_activity=hiking` resta.
it('spiega la attivita primaria: obbligatoria e fra quelle dichiarate in activities', function () {
    $prose = (string) config('scramble.info.description');

    $start = strpos($prose, "### L'attivita' primaria");
    expect($start)->not->toBeFalse();

    $section = substr($prose, $start);
    $next = strpos($section, "\n## ", 10);
    if ($next !== false) {
        $section = substr($section, 0, $next);
    }

    expect($section)
        ->toContain('`primary_activity`')
        ->toContain('Obbligatorio')
        ->toContain('Deve essere una delle attivita\' dichiarate in `activities`')
        ->toContain('422');
});

// Pinna la sezione dei soggetti: `keeping_subjects` e' facoltativo e quindi non entra nella
// rete di sicurezza del primo test, che guarda solo i campi obbligatori.
it('spiega le quattro liste di soggetti, custode compreso', function () {
    $prose = (string) config('scramble.info.description');

    $start = strpos($prose, '## I soggetti del sentiero');
    expect($start)->not->toBeFalse();

    // La descrizione arriva da un heredoc con il marcatore di chiusura
    // indentato: PHP toglie l'indentazione, quindi i titoli cominciano a
    // inizio riga. Cercare "\n        ## " non troverebbe mai il titolo
    // successivo e la «sezione» sarebbe tutto il resto della pagina.
    $section = substr($prose, $start);
    $next = strpos($section, "\n## ", 10);
    if ($next !== false) {
        $section = substr($section, 0, $next);
    }

    expect($section)
        ->toContain('`managing_subjects`')
        ->toContain('`surveying_subjects`')
        ->toContain('`maintaining_subjects`')
        ->toContain('`keeping_subjects`')
        ->toContain('`organization`')
        ->toContain('`person`')
        ->toContain('facoltativo')
        ->toContain('contata da zero');
});

it('non nomina piu i campi delle categorie e dei comuni', function () {
    $prose = (string) config('scramble.info.description');
    $form = file_get_contents(resource_path('views/vendor/scramble/docs.blade.php'));

    foreach ([
        'soggetto_gestore', 'soggetto_rilevatore', 'soggetto_manutentore', 'soggetto_custode',
        'proposed_managing_municipalities', 'municipality_email',
    ] as $campo) {
        expect($prose)->not->toContain($campo);
        expect($form)->not->toContain($campo);
    }
});

// L'esempio `curl` della pagina e il modulo precompilato del bottone di prova sono due copie
// dello stesso payload: se divergono, il bottone manda una chiamata che il canale rifiuta.
it('pubblica due esempi identici, e che il canale accetterebbe', function () {
    // Confrontare i soli nomi dei campi non basta: una virgola spostata o un
    // `subject` scritto male nel modulo di prova lo farebbe rispondere 422
    // lasciando il test verde. Qui i due payload si estraggono, si decodificano
    // e si validano con le regole vere dello schema.
    $prose = (string) config('scramble.info.description');
    $form = file_get_contents(resource_path('views/vendor/scramble/docs.blade.php'));
    $rules = app(ApplicationSchema::class)->rules();

    foreach (['managing_subjects', 'surveying_subjects', 'maintaining_subjects'] as $campo) {
        expect(preg_match("/-F {$campo}='(.*)'/", $prose, $daCurl))->toBe(1, "manca nel curl: {$campo}");
        expect(preg_match("/{$campo}: '(.*)',/", $form, $daForm))->toBe(1, "manca nel modulo di prova: {$campo}");

        expect($daForm[1])->toBe($daCurl[1], "l'esempio curl e il modulo di prova divergono su {$campo}");

        $soggetti = json_decode($daCurl[1], true);
        expect($soggetti)->toBeArray("non e' JSON valido: {$campo}");

        $errori = Validator::make([$campo => $soggetti], $rules)->errors()->keys();
        $suQuestoCampo = array_filter($errori, fn (string $k) => str_starts_with($k, $campo));

        expect(array_values($suQuestoCampo))->toBe([], "il canale rifiuterebbe l'esempio di {$campo}");
    }
});

// Il canale ha smesso di accettare in silenzio i campi che non conosce: chi
// integra deve leggerlo nella pagina, non scoprirlo da un 422.
it('avverte che un campo o un valore non concordato viene rifiutato', function () {
    $prose = (string) config('scramble.info.description');

    $start = strpos($prose, '## Campi e valori concordati');
    expect($start)->not->toBeFalse();

    $section = substr($prose, $start);
    $next = strpos($section, "\n## ", 10);
    if ($next !== false) {
        $section = substr($section, 0, $next);
    }

    expect($section)
        ->toContain('422')
        ->toContain('non è previsto')
        ->toContain('chiavi concordate');
});

it('elenca i dieci tematismi concordati', function () {
    $prose = (string) config('scramble.info.description');

    foreach ([
        'history-archaeology', 'mining-geology', 'transhumance', 'spirituality', 'woods-forests',
        'flora-fauna', 'waters', 'sea-coast', 'villages', 'rural-landscape',
    ] as $tema) {
        expect($prose)->toContain($tema);
    }
});

// Il documento OpenAPI si compone da due parti: le regole della Form Request
// danno tipi, `enum` e forma degli elementi di una lista; gli attributi
// `#[BodyParameter]` danno descrizione ed esempio. Tenere solo gli attributi —
// come si faceva prima di oc:9039 — pubblicava i soggetti come lista di
// stringhe, e chi genera un client dal documento otteneva il tipo sbagliato.
it('pubblica la forma vera degli elementi di una lista di soggetti', function () {
    $corpo = corpoDellaCreazione();

    expect($corpo['properties']['managing_subjects']['items']['type'])->toBe('object')
        ->and($corpo['properties']['managing_subjects']['items']['properties'])
        ->toHaveKeys(['name', 'email', 'subject'])
        ->and($corpo['properties']['managing_subjects']['items']['properties']['subject']['enum'])
        ->toBe(['organization', 'person']);
});

it('pubblica i valori ammessi dei tematismi come enum', function () {
    $corpo = corpoDellaCreazione();

    expect($corpo['properties']['themes']['items']['enum'])
        ->toContain('history-archaeology', 'spirituality', 'rural-landscape');
});

it('tiene insieme lo schema delle regole e la descrizione degli attributi', function () {
    $corpo = corpoDellaCreazione();
    $campo = $corpo['properties']['existing_signage'];

    expect($campo['enum'])->toBe(['good_condition', 'dilapidated', 'absent'])
        ->and($campo['description'])->toContain('Campo 38 del format');
});

// Il corpo deve restare un oggetto piatto: Stoplight Elements, il renderer
// della pagina, non raccoglie i campi di un `multipart/form-data` il cui schema
// e' un `allOf`, e il bottone di prova partirebbe con un corpo vuoto.
it('non lascia il corpo della creazione come allOf', function () {
    expect(corpoDellaCreazione())->not->toHaveKey('allOf');
});
