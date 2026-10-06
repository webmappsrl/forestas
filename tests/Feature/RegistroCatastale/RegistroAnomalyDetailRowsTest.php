<?php

use App\Services\RegistroCatastale\AnomalyTypes\LinkOrfano;
use App\Services\RegistroCatastale\AnomalyTypes\NumeroDiverso;
use App\Services\RegistroCatastale\AnomalyTypes\RigheMultiple;
use App\Services\RegistroCatastale\AnomalyTypes\RipiegoAmbiguo;
use App\Services\RegistroCatastale\AnomalyTypes\ValoreNonSanitizzabile;
use App\Services\RegistroCatastale\RegistroAnomalyTypes;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * Le righe di dettaglio delle anomalie del registro (oc:8539). Il link arriva
 * da una cella del foglio Google, scritta a mano: diventa cliccabile solo se
 * schema e host sono davvero quelli di Sardegna Sentieri.
 */
function anomaliaRegistro(string $type, array $context): TrailRegistryAnomaly
{
    return new TrailRegistryAnomaly([
        'type' => $type,
        'source' => RegistroAnomalyTypes::SOURCE,
        'context' => ['sheet' => 'Z-SU-D', 'gid' => '0', 'row' => 34, ...$context],
    ]);
}

/** Il valore della riga «Link», o null se la riga non c'e'. */
function valoreLink(array $rows): ?string
{
    foreach ($rows as [$label, $value]) {
        if ($label === __('Link')) {
            return $value;
        }
    }

    return null;
}

it('un link javascript che nomina sardegnasentieri.it non diventa cliccabile', function (string $link) {
    $rows = (new LinkOrfano)->detailRows(anomaliaRegistro(RegistroAnomalyTypes::LINK_ORFANO, ['link' => $link]));

    expect(valoreLink($rows))->toBe(e($link));
    expect(valoreLink($rows))->not->toContain('<a ');
})->with([
    'javascript:alert(1)//sardegnasentieri.it',
    'JavaScript:alert(1)//www.sardegnasentieri.it/node/1',
    'data:text/html,sardegnasentieri.it',
    'https://sardegnasentieri.it.evil.example/node/1',
    'https://evil.example/?next=sardegnasentieri.it',
    'https://evilsardegnasentieri.it/node/1',
]);

it('un link di Sardegna Sentieri diventa cliccabile', function (string $link) {
    $rows = (new LinkOrfano)->detailRows(anomaliaRegistro(RegistroAnomalyTypes::LINK_ORFANO, ['link' => $link]));

    expect(valoreLink($rows))->toBe('<a href="'.e($link).'" target="_blank" rel="noopener">'.e($link).'</a>');
})->with([
    'https://www.sardegnasentieri.it/node/2576',
    'http://sardegnasentieri.it/sentiero/sedilo-iloi-g-610',
]);

it('numero diverso mostra il codice completo del catasto', function () {
    $rows = (new NumeroDiverso)->detailRows(anomaliaRegistro(RegistroAnomalyTypes::NUMERO_DIVERSO, [
        'link' => '',
        'code' => 'ZSSG506C',
        'sheet_code' => 'G602C',
    ]));

    expect($rows)->toContain([__('Trail registry code'), 'ZSSG506C'])
        ->toContain([__('Sheet code'), 'G602C']);
});

it('ripiego ambiguo mostra i codici candidati', function () {
    $rows = (new RipiegoAmbiguo)->detailRows(anomaliaRegistro(RegistroAnomalyTypes::RIPIEGO_AMBIGUO, [
        'link' => '',
        'candidates' => ['ZNUD332', 'ZORD332'],
    ]));

    expect($rows)->toContain([__('Candidate codes'), 'ZNUD332, ZORD332']);
});

it('righe multiple elenca le righe del foglio e le righe comuni', function () {
    $rows = (new RigheMultiple)->detailRows(anomaliaRegistro(RegistroAnomalyTypes::RIGHE_MULTIPLE, [
        'link' => '',
        'code' => 'ZSSG506C',
        'rows' => [['sheet' => 'Z-SU-D', 'row' => 34], ['sheet' => 'Z-SU-D', 'row' => 35]],
    ]));

    expect($rows)->toContain([__('Sheet'), 'Z-SU-D'])
        ->toContain([__('Rows'), e(__(':sheet · row :row', ['sheet' => 'Z-SU-D', 'row' => '34']).', '.__(':sheet · row :row', ['sheet' => 'Z-SU-D', 'row' => '35']))]);
});

it('valore non sanitizzabile mostra colonna e valore', function () {
    $rows = (new ValoreNonSanitizzabile)->detailRows(anomaliaRegistro(RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE, [
        'link' => '',
        'column' => 'Lunghezza',
        'value' => '12 km <b>?</b>',
    ]));

    expect($rows)->toContain([__('Sheet'), 'Z-SU-D'])
        ->toContain([__('Column'), 'Lunghezza'])
        ->toContain([__('Value'), e('12 km <b>?</b>')]);
});
