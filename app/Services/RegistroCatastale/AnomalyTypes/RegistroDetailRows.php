<?php

namespace App\Services\RegistroCatastale\AnomalyTypes;

use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * Le righe di dettaglio comuni a tutte le anomalie del registro catastale
 * (oc:8539): foglio, riga e link, presi dal `context` scritto dall'importer.
 * Il link si mostra come collegamento cliccabile solo se e' davvero un URL
 * di Sardegna Sentieri: altrove e' solo un valore da leggere, non da aprire.
 */
trait RegistroDetailRows
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    private function commonRows(TrailRegistryAnomaly $anomaly): array
    {
        $context = $anomaly->context ?? [];

        $rows = [
            [__('Foglio'), e((string) ($context['sheet'] ?? ''))],
            [__('Riga'), e((string) ($context['row'] ?? ''))],
        ];

        $link = (string) ($context['link'] ?? '');

        if ($link !== '') {
            $rows[] = [__('Link'), $this->linkValue($link)];
        }

        return $rows;
    }

    private function linkValue(string $link): string
    {
        if (! $this->isSardegnaSentieriUrl($link)) {
            return e($link);
        }

        return '<a href="'.e($link).'" target="_blank" rel="noopener">'.e($link).'</a>';
    }

    /**
     * Il link arriva dal foglio Google, scritto a mano da chiunque vi abbia
     * accesso: basta che contenga `sardegnasentieri.it` perche' sembri buono
     * anche un `javascript:alert(1)//sardegnasentieri.it`. Si controllano
     * quindi schema e host veri, non il testo.
     */
    private function isSardegnaSentieriUrl(string $link): bool
    {
        $parts = parse_url($link);

        if ($parts === false) {
            return false;
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return $host === 'sardegnasentieri.it' || str_ends_with($host, '.sardegnasentieri.it');
    }
}
