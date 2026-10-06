<?php

namespace App\Services\RegistroCatastale;

use App\Models\RegistroCatastaleRow;
use Illuminate\Support\Facades\DB;
use LogicException;
use Wm\WmPackage\TrailRegistry\Anomalies\TrailRegistryAnomalyTypes;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * Il giro completo del registro catastale (oc:8539): scarica tutti i fogli,
 * li fa passare per parser e matcher, e scrive mirror e anomalie in
 * un'unica transazione.
 *
 * Tutto si scarica e si valida PRIMA di cancellare: un foglio che non
 * risponde (RegistroCatastaleReadException dal reader) lascia intatto il
 * giro precedente, perche' l'eccezione arriva prima di toccare il database.
 */
class RegistroCatastaleImporter
{
    public function __construct(
        private readonly RegistroCatastaleSheetReader $reader,
        private readonly RegistroCatastaleRowParser $parser,
        private readonly RegistroCatastaleMatcher $matcher,
        private readonly RegistroCatastaleTrackWriter $writer,
    ) {}

    public function run(): RegistroCatastaleImportResult
    {
        $allSheets = $this->reader->readAll();

        // Nel log finiscono tutti i fogli letti, non solo quelli di registro:
        // se il cliente rinomina una colonna chiave, il foglio smette di
        // essere riconosciuto e l'unico segno e' il `registro: false` qui.
        $sheetsLog = array_map(fn (RegistroCatastaleSheet $s) => [
            'name' => $s->name,
            'gid' => $s->gid,
            'rows' => max(count($s->rows) - 1, 0),
            'registro' => $this->parser->isRegistro($s),
        ], $allSheets);

        $sheets = array_values(array_filter($allSheets, fn (RegistroCatastaleSheet $s) => $this->parser->isRegistro($s)));

        $rows = $sheets === []
            ? []
            : array_merge(...array_map(fn (RegistroCatastaleSheet $s) => $this->parser->parse($s), $sheets));

        $this->matcher->prepare();
        $now = now();

        $matches = array_map(fn (RegistroCatastaleParsedRow $row) => $this->matcher->match($row), $rows);

        // Una sola riga per codice (oc:8540): fra piu' righe consistenti sullo
        // stesso codice resta quella del link, se e' una sola; le altre (o
        // tutte) si sganciano e il sentiero diventa RIGHE_MULTIPLE.
        $byCode = [];
        foreach ($matches as $i => $match) {
            if ($match->codeId !== null) {
                $byCode[$match->codeId][] = $i;
            }
        }

        $multiTrackIds = [];
        $multiAnomalies = [];

        foreach ($byCode as $codeId => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $linked = array_values(array_filter($indexes, fn (int $i) => $matches[$i]->byLink));
            $keep = count($linked) === 1 ? $linked[0] : null;

            // Il codice si legge prima dello sgancio: serve nel context, cosi'
            // la Tab «Registro» trova l'anomalia anche senza sentiero.
            $code = $this->matcher->codeString($codeId);

            foreach ($indexes as $i) {
                if ($i !== $keep) {
                    $matches[$i]->codeId = null;
                }
            }

            // Foglio, riga e link sono quelli della riga rimasta agganciata,
            // se c'e'; altrimenti della prima del gruppo.
            $main = $rows[$keep ?? $indexes[0]];
            $trackId = $matches[$keep ?? $indexes[0]]->ecTrackId;

            if ($trackId === null) {
                foreach ($indexes as $i) {
                    $trackId ??= $matches[$i]->ecTrackId;
                }
            }

            if ($trackId !== null) {
                $multiTrackIds[$trackId] = true;
            }

            $multiAnomalies[] = [
                'ec_track_id' => $trackId,
                'type' => RegistroAnomalyTypes::RIGHE_MULTIPLE,
                'source' => RegistroAnomalyTypes::SOURCE,
                'created_at' => $now,
                'context' => json_encode([
                    'sheet' => $main->sheetName,
                    'gid' => $main->gid,
                    'row' => $main->rowNumber,
                    'link' => $main->link,
                    'code' => $code,
                    'rows' => array_map(fn (int $i) => ['sheet' => $rows[$i]->sheetName, 'row' => $rows[$i]->rowNumber], $indexes),
                ]),
            ];
        }

        $mirror = [];
        $anomalies = [];
        $rowsBySheet = [];
        $anomaliesByType = [];
        $consistentRows = 0;

        foreach ($rows as $i => $row) {
            $rowsBySheet[$row->sheetName] = ($rowsBySheet[$row->sheetName] ?? 0) + 1;

            $match = $matches[$i];

            if ($match->codeId !== null) {
                $consistentRows++;
            }

            $mirror[] = [
                'sheet_gid' => $row->gid,
                'sheet_name' => $row->sheetName,
                'row_number' => $row->rowNumber,
                'cells' => json_encode($row->cells),
                'link' => $row->link,
                'area' => $row->area,
                'sector' => $row->sector,
                'number' => $row->number,
                'variant' => $row->variant,
                'trail_registry_code_id' => $match->codeId,
                'imported_at' => $now,
            ];

            if ($match->anomalyType !== null) {
                // Il vincolo sulla colonna `type` e' applicativo, non un CHECK
                // (vedi la migration): se il matcher scrivesse un tipo non
                // dichiarato in TrailRegistryAnomalyTypes, la riga entrerebbe
                // comunque in tabella ma sparirebbe dall'elenco delle
                // anomalie (che filtra per tipo noto) senza che nessuno se ne
                // accorga. Meglio fallire qui, con un tipo e una riga precisi.
                if (! TrailRegistryAnomalyTypes::isKnown($match->anomalyType)) {
                    throw new LogicException(
                        "RegistroCatastaleMatcher ha prodotto un anomalyType sconosciuto: «{$match->anomalyType}» (foglio «{$row->sheetName}», riga {$row->rowNumber})."
                    );
                }

                $anomaliesByType[$match->anomalyType] = ($anomaliesByType[$match->anomalyType] ?? 0) + 1;

                $anomalies[] = [
                    'ec_track_id' => $match->ecTrackId,
                    'type' => $match->anomalyType,
                    'source' => RegistroAnomalyTypes::SOURCE,
                    'created_at' => $now,
                    'context' => json_encode([
                        'sheet' => $row->sheetName,
                        'gid' => $row->gid,
                        'row' => $row->rowNumber,
                        'link' => $row->link,
                        ...$match->context,
                    ]),
                ];
            }
        }

        $anomalies = [...$anomalies, ...$multiAnomalies];

        if ($multiAnomalies !== []) {
            $anomaliesByType[RegistroAnomalyTypes::RIGHE_MULTIPLE] = count($multiAnomalies);
        }

        // Sentieri con una riga agganciata e non esclusi da RIGHE_MULTIPLE (oc:8540).
        $writes = [];

        foreach ($rows as $i => $row) {
            $trackId = $matches[$i]->ecTrackId;

            if ($matches[$i]->codeId === null || $trackId === null || isset($multiTrackIds[$trackId])) {
                continue;
            }

            $write = $this->writer->evaluate($row, $trackId);
            $writes[] = $write;

            foreach ($write->invalid as $cell) {
                $anomalies[] = [
                    'ec_track_id' => $trackId,
                    'type' => RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE,
                    'source' => RegistroAnomalyTypes::SOURCE,
                    'created_at' => $now,
                    'context' => json_encode([
                        'sheet' => $row->sheetName,
                        'gid' => $row->gid,
                        'row' => $row->rowNumber,
                        'link' => $row->link,
                        ...$cell,
                    ]),
                ];
                $anomaliesByType[RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE] = ($anomaliesByType[RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE] ?? 0) + 1;
            }
        }

        $tracksUpdated = 0;

        DB::transaction(function () use ($mirror, $anomalies, $writes, &$tracksUpdated) {
            RegistroCatastaleRow::query()->delete();
            TrailRegistryClasses::anomaly()::query()->fromSource(RegistroAnomalyTypes::SOURCE)->delete();

            foreach (array_chunk($mirror, 500) as $chunk) {
                DB::table('registro_catastale_rows')->insert($chunk);
            }

            foreach (array_chunk($anomalies, 500) as $chunk) {
                DB::table('trail_registry_anomalies')->insert($chunk);
            }

            foreach ($writes as $write) {
                if ($this->writer->apply($write)) {
                    $tracksUpdated++;
                }
            }
        });

        return new RegistroCatastaleImportResult($rowsBySheet, $consistentRows, $anomaliesByType, $sheetsLog, tracksUpdated: $tracksUpdated);
    }
}
