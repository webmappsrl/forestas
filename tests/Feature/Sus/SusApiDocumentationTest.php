<?php

use Wm\WmPackage\TrailRegistry\Schema\ApplicationSchema;

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
