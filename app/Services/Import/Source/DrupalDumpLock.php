<?php

declare(strict_types=1);

namespace App\Services\Import\Source;

use Carbon\CarbonImmutable;

/**
 * Il lock che scripts/drupal-dump-reload.sh tiene mentre ricarica la copia
 * del DB Drupal (oc:8900).
 *
 * La ricarica fa `DROP DATABASE` e carica una tabella alla volta, mentre
 * l'utente `readonly` resta valido: chi legge in quei minuti trova nodi senza
 * immagini né GPX, e l'import cancellerebbe le immagini già scaricate. Lo
 * script toglie il lock solo a ricarica riuscita: dopo una ricarica fallita
 * resta, e l'import resta fermo invece di leggere una copia a metà.
 */
final class DrupalDumpLock
{
    public static function isHeld(): bool
    {
        return is_file(self::path());
    }

    public static function heldSince(): ?CarbonImmutable
    {
        $time = self::isHeld() ? filemtime(self::path()) : false;

        return $time === false ? null : CarbonImmutable::createFromTimestamp($time);
    }

    private static function path(): string
    {
        return (string) config('forestas.sardegnasentieri.reload_lock_path');
    }
}
