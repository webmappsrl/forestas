<?php

namespace App\Services\RegistroCatastale;

/**
 * I conteggi di un giro di RegistroCatastaleImporter::run() (oc:8539): quante
 * righe per foglio, quante consistenti col catasto, quante anomalie per
 * tipo. Solo numeri: serve a chi lancia l'import da tinker o da un comando,
 * non un riepilogo da mostrare all'utente.
 */
class RegistroCatastaleImportResult
{
    /**
     * @param  array<string, int>  $rowsBySheet  nome del foglio => numero di righe lette
     * @param  array<string, int>  $anomaliesByType  costante di RegistroAnomalyTypes => numero di anomalie
     * @param  list<array{name: string, gid: string, rows: int, registro: bool}>  $sheets  tutti i fogli letti, registro o no: `rows` sono le righe del CSV senza l'intestazione
     */
    public function __construct(
        public readonly array $rowsBySheet,
        public readonly int $consistentRows,
        public readonly array $anomaliesByType,
        public readonly array $sheets,
    ) {}

    /**
     * Il contesto della riga di log di fine giro, uguale per job e comando.
     *
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return [
            'sheets' => $this->sheets,
            'rows_by_sheet' => $this->rowsBySheet,
            'consistent_rows' => $this->consistentRows,
            'anomalies_by_type' => $this->anomaliesByType,
        ];
    }
}
