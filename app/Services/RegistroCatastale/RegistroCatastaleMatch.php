<?php

namespace App\Services\RegistroCatastale;

/**
 * L'esito dell'aggancio di una riga del registro (oc:8539).
 *
 * `codeId` e' valorizzato solo se la riga e' consistente col catasto.
 * `anomalyType` e' una delle costanti di RegistroAnomalyTypes, oppure
 * `null` se la riga non e' ne' consistente ne' anomala — il caso dei numeri
 * prenotati senza scheda su Sardegna Sentieri, che il cliente ha chiesto di
 * non trattare come un problema.
 */
class RegistroCatastaleMatch
{
    /**
     * @param  array<string, mixed>  $context  dati aggiuntivi specifici della regola che ha prodotto l'esito
     */
    public function __construct(
        public ?int $codeId,
        public ?string $anomalyType,
        public ?int $ecTrackId,
        public array $context = [],
    ) {}
}
