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

        $mirror = [];
        $anomalies = [];
        $rowsBySheet = [];
        $anomaliesByType = [];
        $consistentRows = 0;

        foreach ($rows as $row) {
            $rowsBySheet[$row->sheetName] = ($rowsBySheet[$row->sheetName] ?? 0) + 1;

            $match = $this->matcher->match($row);

            if ($match->codeId !== null) {
                $consistentRows++;
            }

            $mirror[] = [
                'sheet_gid' => $row->gid,
                'sheet_name' => $row->sheetName,
                'row_number' => $row->rowNumber,
                'cells' => json_encode($row->cells),
                'link' => $row->link,
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

        DB::transaction(function () use ($mirror, $anomalies) {
            RegistroCatastaleRow::query()->delete();
            TrailRegistryClasses::anomaly()::query()->fromSource(RegistroAnomalyTypes::SOURCE)->delete();

            foreach (array_chunk($mirror, 500) as $chunk) {
                DB::table('registro_catastale_rows')->insert($chunk);
            }

            foreach (array_chunk($anomalies, 500) as $chunk) {
                DB::table('trail_registry_anomalies')->insert($chunk);
            }
        });

        return new RegistroCatastaleImportResult($rowsBySheet, $consistentRows, $anomaliesByType, $sheetsLog);
    }
}
