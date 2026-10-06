<?php

/**
 * Le chiavi di __() in app/ sono in inglese, come nel resto della
 * piattaforma, e la voce italiana riporta esattamente il testo che
 * l'operatore leggeva quando la chiave era italiana (oc:8672).
 *
 * L'elenco atteso e' stato ricavato dal codice PRIMA della conversione (la
 * «Tabella delle chiavi» di docs/features/8672-*): non va mai rigenerato da
 * lang/it.json, altrimenti il test confronterebbe il file con se' stesso. I
 * test che usano __('chiave') da entrambi i lati non proteggono il testo
 * italiano, e la CI gira con APP_LOCALE=en: la garanzia sta qui.
 *
 * Le voci si cercano come le carica Laravel: quelle del package
 * (wm-package/resources/lang) piu' quelle di forestas (lang/), che vincono.
 * Le chiavi gia' inglesi senza voce vengono solo elencate su STDERR.
 *
 * Sta in Unit perche' legge solo file: non serve il database.
 */

/**
 * Chiave inglese => testo italiano di oggi.
 *
 * @return array<string, string>
 */
function oc8672ForestasExpectedKeys(): array
{
    return [
        'Forest complex' => 'Complesso forestale',
        'Partner body' => 'Ente partner',
        'Other public institutions' => 'Altre Pubbliche Istituzioni',
        'Private/association' => 'Privato/associazione',
        'Municipality' => 'Comune',
        'Invalid credentials.' => 'Credenziali non valide.',
        'This endpoint is reserved for the SUS client.' => 'Questo endpoint e\' riservato al client SUS.',
        'Unable to refresh the token: log in again.' => 'Impossibile rinnovare il token: rieseguire il login.',
        'Create SUS client' => 'Crea client SUS',
        'A user with the email :email already exists. To change the password use the Password field on their record.' => 'Esiste gia\' un utente con l\'email :email. Per cambiare la password usa il campo Password sulla sua scheda.',
        'SUS client :email created with the Sus role.' => 'Client SUS :email creato con il ruolo Sus.',
        'Name' => 'Nome',
        'Suggested automatically: copy it before confirming, or replace it.' => 'Suggerita automaticamente: copiala prima di confermare, oppure sostituiscila.',
        'Import TaxonomyWhere (Cadastral areas)' => 'Import TaxonomyWhere (Aree Catastali)',
        'SUS API documentation' => 'Documentazione API SUS',
        'Trail' => 'Sentiero',
        'Trail registry' => 'Catasto',
        'Registry' => 'Registro',
        'Sheet' => 'Foglio',
        'Row' => 'Riga',
        'Rows' => 'Righe',
        'Column' => 'Colonna',
        'Value' => 'Valore',
        'Imported on' => 'Importato il',
        'Candidate codes' => 'Codici candidati',
        'Trail registry code' => 'Codice del catasto',
        'Sheet code' => 'Codice del foglio',
        'The registry has no row for this code.' => 'Il registro non ha una riga per questo codice.',
        'This row is not linked to any code.' => 'Questa riga non è agganciata a nessun codice.',
        'If it ended up as an anomaly, you will find it in Trail registry › Anomalies, source filter Registry.' => 'Se è finita in anomalia la trovi in Catasto › Anomalie, filtro provenienza Registro.',
        'The registry row for this code has become an anomaly:' => 'La riga del registro per questo codice è finita in anomalia:',
        'view the anomaly' => 'vedi l\'anomalia',
        'Anomalies from the registry' => 'Le anomalie del registro',
        'Registry link with no matching track' => 'Link del registro senza traccia corrispondente',
        'Registry number different from the trail registry' => 'Numero del registro diverso da quello del catasto',
        'Multiple registry rows for the same code' => 'Più righe del registro per lo stesso codice',
        'Multiple candidate codes for the same registry number' => 'Più codici candidati per lo stesso numero del registro',
        'Registry track without an active code' => 'Traccia del registro senza codice attivo',
        'Unreadable registry value' => 'Valore del registro non interpretabile',
        'These are rows from the registro catastale Google Sheet that did not cleanly attach to a trail registry code: a number different from the sheet, a link that leads to no track, a track without an active code, a number the fallback cannot attribute to a single code, several sheet rows for the same code, or length or time values that cannot be interpreted. These are also corrected at the source — the sheet or the trail record — not here: the next import run makes them disappear on their own once the data is consistent again.' => 'Sono le righe del foglio Google del registro catastale che non si sono agganciate in modo pulito a un codice del Catasto: un numero diverso da quello sul foglio, un link che non porta a nessuna traccia, una traccia senza codice attivo, un numero che il ripiego non sa attribuire a un solo codice, più righe del foglio per lo stesso codice, o valori di lunghezza o tempi che non si riescono a interpretare. Anche queste si correggono alla fonte — il foglio o la scheda del sentiero — non da qui: il prossimo giro dell\'import le fa sparire da sole quando il dato torna coerente.',
    ];
}

function oc8672ForestasPath(string $relative = ''): string
{
    return dirname(__DIR__, 2).($relative === '' ? '' : '/'.$relative);
}

/**
 * Le voci di una lingua come le vede Laravel: package, poi forestas sopra.
 *
 * @return array<string, string>
 */
function oc8672ForestasLang(string $locale): array
{
    $read = fn (string $path) => json_decode(file_get_contents(oc8672ForestasPath($path)), true, flags: JSON_THROW_ON_ERROR);

    return array_merge($read("wm-package/resources/lang/{$locale}.json"), $read("lang/{$locale}.json"));
}

function oc8672ForestasUnquote(string $literal): string
{
    $body = substr($literal, 1, -1);

    return $literal[0] === "'"
        ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
        : stripcslashes($body);
}

/**
 * Le chiamate __() in app/, lette con il tokenizer e non con una regex: cosi'
 * sono corrette anche le chiamate su piu' righe (CreateSusClient) e gli
 * apici con escape (SusAuthController).
 *
 * @return array{literal: array<int, array{0: string, 1: int, 2: string}>, dynamic: array<int, array{0: string, 1: int}>}
 */
function oc8672ForestasKeys(): array
{
    $found = ['literal' => [], 'dynamic' => []];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(oc8672ForestasPath('app'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(oc8672ForestasPath().'/', '', $file->getPathname());
        $tokens = array_values(array_filter(
            token_get_all(file_get_contents($file->getPathname())),
            fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== '__' || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;

            if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $argument = $tokens[$i + 2] ?? null;
            $after = $tokens[$i + 3] ?? null;

            if (is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING && in_array($after, [',', ')'], true)) {
                $found['literal'][] = [$relative, $token[2], oc8672ForestasUnquote($argument[1])];
            } else {
                $found['dynamic'][] = [$relative, $token[2]];
            }
        }
    }

    return $found;
}

it('ha in en e it ogni chiave convertita, con il testo italiano di oggi', function () {
    $en = oc8672ForestasLang('en');
    $it = oc8672ForestasLang('it');
    $wrong = [];

    foreach (oc8672ForestasExpectedKeys() as $key => $italian) {
        if (($en[$key] ?? null) !== $key) {
            $wrong[] = "en: «{$key}»";
        }

        if (($it[$key] ?? null) !== $italian) {
            $wrong[] = "it: «{$key}» (atteso «{$italian}», trovato «".($it[$key] ?? 'nessuna voce').'»)';
        }
    }

    expect($wrong)->toBe([]);
});

it('non usa piu\' nessuna delle chiavi italiane convertite in app/', function () {
    $italian = array_flip(oc8672ForestasExpectedKeys());
    $left = [];

    foreach (oc8672ForestasKeys()['literal'] as [$file, $line, $key]) {
        if (isset($italian[$key])) {
            $left[] = "{$file}:{$line} «{$key}»";
        }
    }

    expect($left)->toBe([]);
});

it('elenca le chiavi gia\' inglesi senza voce e le chiamate dinamiche, senza verificarle', function () {
    $expected = oc8672ForestasExpectedKeys();
    $en = oc8672ForestasLang('en');
    $keys = oc8672ForestasKeys();
    $unlisted = [];

    foreach ($keys['literal'] as [$file, $line, $key]) {
        if (! isset($expected[$key]) && ! isset($en[$key])) {
            $unlisted[] = "  {$file}:{$line} «{$key}»";
        }
    }

    $dynamic = array_map(fn (array $call) => "  {$call[0]}:{$call[1]}", $keys['dynamic']);

    fwrite(STDERR, "\nChiavi letterali senza voce, non verificate (oc:8672):\n".implode("\n", $unlisted)
        ."\nChiamate __() con chiave dinamica, non verificate:\n".implode("\n", $dynamic)."\n");

    expect($keys['literal'])->not->toBeEmpty();
});
