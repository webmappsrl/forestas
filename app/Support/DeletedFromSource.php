<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Filtro SQL condiviso per i contenuti spariti da Drupal: l'import li marca con
 * `properties.forestas.deleted_from_source` invece di cancellarli, quindi le API pubbliche
 * devono escluderli esplicitamente.
 */
final class DeletedFromSource
{
    /**
     * Frammento SQL (per `whereRaw`) che tiene i record non marcati come cancellati alla fonte.
     * `IS DISTINCT FROM` mantiene anche i record senza la chiave (NULL).
     */
    public static function notDeletedSql(string $table): string
    {
        return "({$table}.properties->'forestas'->>'deleted_from_source') IS DISTINCT FROM 'true'";
    }
}
