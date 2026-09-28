<?php

namespace App\Services\RegistroCatastale;

/**
 * Un foglio del file Google del registro catastale (oc:8539): un foglio di
 * registro vero e proprio, oppure uno dei fogli di legenda. Il parsing e
 * il match con i codici del Catasto arrivano nei task successivi: qui il
 * foglio e' solo dati grezzi.
 */
class RegistroCatastaleSheet
{
    /**
     * @param  list<list<string>>  $rows  prima riga = intestazioni
     */
    public function __construct(
        public string $gid,
        public string $name,
        public array $rows,
    ) {}
}
