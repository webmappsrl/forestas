<?php

namespace App\Services\RegistroCatastale;

/**
 * Cosa una riga del registro scrive su un sentiero (oc:8540), gia' letto e
 * sanitizzato. `null` = non scrivere; `$invalid` = celle illeggibili, che
 * diventano anomalie VALORE_NON_SANITIZZABILE.
 */
final class RegistroTrackWrite
{
    /**
     * @param  array<string, int|float>  $manual  chiavi di manual_data da scrivere
     * @param  list<array{column: string, value: string}>  $invalid
     */
    public function __construct(
        public readonly int $trackId,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?string $via,
        public readonly array $manual,
        public readonly array $invalid,
    ) {}
}
