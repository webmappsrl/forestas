<?php

namespace App\Services\RegistroCatastale;

/**
 * Una riga letta dal registro catastale (oc:8539): gia' filtrata (ha un
 * numero leggibile o un link) e con le colonne chiave estratte. Il match
 * con i codici del Catasto arriva nei task successivi.
 */
class RegistroCatastaleParsedRow
{
    /**
     * @param  list<array{header: string, value: string}>  $cells  tutte le colonne del foglio, nell'ordine originale
     * @param  ?string  $area  lettera d'area (es. `B`)
     * @param  ?string  $sector  cifra del settore
     * @param  ?int  $number  numero 0-99
     * @param  string  $variant  lettera di variante, `'0'` se assente
     */
    public function __construct(
        public string $gid,
        public string $sheetName,
        public int $rowNumber,
        public array $cells,
        public string $link,
        public ?string $area,
        public ?string $sector,
        public ?int $number,
        public string $variant,
    ) {}
}
