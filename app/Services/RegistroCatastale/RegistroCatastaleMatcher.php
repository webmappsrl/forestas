<?php

namespace App\Services\RegistroCatastale;

use Illuminate\Support\Facades\DB;
use LogicException;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * Aggancia una riga del registro catastale a un codice del Catasto
 * Sentieri (oc:8539): link → traccia → codice, con ripiego sul numero senza
 * provincia quando il link manca o non porta a nessuna traccia.
 *
 * Le mappe si caricano una volta con query SQL sulle colonne JSON, senza
 * caricare le geometrie: `prepare()` va chiamato prima di ogni `match()`, e
 * riletto a ogni giro di normalizzazione (il registro e i codici cambiano
 * fra un giro e l'altro).
 */
class RegistroCatastaleMatcher
{
    /** @var array<string, int>|null source_id (Drupal) → ec_track_id */
    private ?array $trackBySourceId = null;

    /** @var array<string, int>|null URL normalizzato → ec_track_id */
    private ?array $trackByNormalizedUrl = null;

    /** @var array<int, array{id: int, code: string, area: string, sector: string, number: int, variant: string, ec_track_id: ?int}>|null ec_track_id → codice attivo */
    private ?array $activeCodeByTrack = null;

    /** @var array<string, list<array{id: int, code: string, area: string, sector: string, number: int, variant: string, ec_track_id: ?int}>>|null area|settore|numero|variante → codici attivi */
    private ?array $codesByKey = null;

    /** @var array<int, bool>|null ec_track_id → ha almeno un'anomalia del catasto */
    private ?array $tracksWithCatastoAnomaly = null;

    /**
     * Carica una volta le mappe usate da `match()`. Va richiamato a ogni
     * giro di normalizzazione: le mappe non si aggiornano da sole.
     */
    public function prepare(): void
    {
        $this->loadTracks();
        $this->loadCodes();
        $this->loadCatastoAnomalies();
    }

    /**
     * Aggancia una riga a un codice, o classifica perche' non ci riesce.
     */
    public function match(RegistroCatastaleParsedRow $row): RegistroCatastaleMatch
    {
        $this->assertPrepared();

        $trackId = $this->resolveTrack($row->link);

        if ($trackId !== null) {
            return $this->matchByTrack($trackId, $row);
        }

        return $this->matchByFallback($row);
    }

    /**
     * Regola 2 e 3: traccia trovata, con o senza codice attivo.
     */
    private function matchByTrack(int $trackId, RegistroCatastaleParsedRow $row): RegistroCatastaleMatch
    {
        $code = $this->activeCodeByTrack[$trackId] ?? null;

        if ($code !== null) {
            if ($this->numberMatches($code, $row)) {
                return new RegistroCatastaleMatch($code['id'], null, $trackId);
            }

            // `code` e' il codice completo del catasto, nella stessa forma di
            // TrailRegistryCode::code (es. `ZSSG506C`): e' quello che la Tab
            // «Registro» confronta. `sheet_code` e' invece come la riga si
            // legge sul foglio, senza regione e provincia (il foglio non le
            // usa per il numero).
            return new RegistroCatastaleMatch(null, RegistroAnomalyTypes::NUMERO_DIVERSO, $trackId, [
                'code' => $code['code'],
                'sheet_code' => TrailRegistryCode::formatCode(null, null, $row->area ?? '', $row->sector ?? '', $row->number ?? 0, $row->variant),
            ]);
        }

        if ($this->tracksWithCatastoAnomaly[$trackId] ?? false) {
            // Il catasto sa gia' che questa traccia e' senza codice (o in
            // conflitto): un'altra anomalia dal registro sarebbe un
            // duplicato dello stesso avviso.
            return new RegistroCatastaleMatch(null, null, $trackId);
        }

        return new RegistroCatastaleMatch(null, RegistroAnomalyTypes::TRACCIA_SENZA_CODICE, $trackId);
    }

    /**
     * Regola 4 e 5: link vuoto o senza traccia, ripiego su area + settore +
     * numero + variante, senza provincia.
     */
    private function matchByFallback(RegistroCatastaleParsedRow $row): RegistroCatastaleMatch
    {
        if ($row->number === null) {
            return $this->orphanOrSilent($row);
        }

        $key = $this->key((string) $row->area, (string) $row->sector, $row->number, $row->variant);
        $candidates = $this->codesByKey[$key] ?? [];

        if (count($candidates) === 1) {
            $code = $candidates[0];

            return new RegistroCatastaleMatch($code['id'], null, $code['ec_track_id']);
        }

        if (count($candidates) > 1) {
            return new RegistroCatastaleMatch(null, RegistroAnomalyTypes::RIPIEGO_AMBIGUO, null, [
                // Codici completi, non id: sono quelli che una persona cerca
                // nel Catasto, e gli id cambiano se un codice si ricrea.
                'candidates' => array_map(fn (array $c) => $c['code'], $candidates),
            ]);
        }

        return $this->orphanOrSilent($row);
    }

    /**
     * Nessun candidato trovato: anomalia solo se c'era un link da cui
     * ripartire. Un link vuoto e' un numero prenotato senza scheda, non un
     * problema — il cliente l'ha chiesto esplicitamente.
     */
    private function orphanOrSilent(RegistroCatastaleParsedRow $row): RegistroCatastaleMatch
    {
        if ($row->link !== '') {
            return new RegistroCatastaleMatch(null, RegistroAnomalyTypes::LINK_ORFANO, null, [
                'link' => $row->link,
            ]);
        }

        return new RegistroCatastaleMatch(null, null, null);
    }

    /**
     * Un codice attivo coincide con la riga se area, settore, numero e
     * variante sono uguali. Il numero della riga puo' essere `null` (colonna
     * illeggibile): in quel caso non e' confrontabile, e conta come
     * coincidente — non e' lui a far scattare l'anomalia.
     *
     * @param  array{id: int, code: string, area: string, sector: string, number: int, variant: string, ec_track_id: ?int}  $code
     */
    private function numberMatches(array $code, RegistroCatastaleParsedRow $row): bool
    {
        if ($row->area !== null && $code['area'] !== $row->area) {
            return false;
        }

        if ($row->sector !== null && $code['sector'] !== $row->sector) {
            return false;
        }

        if ($row->number !== null && $code['number'] !== $row->number) {
            return false;
        }

        return $code['variant'] === $row->variant;
    }

    /**
     * Regola 1: link → traccia. Prima `/node/<id>` per `source_id`, poi —
     * solo se il link non e' un nodo — l'URL parlante normalizzato.
     */
    private function resolveTrack(string $link): ?int
    {
        if ($link === '') {
            return null;
        }

        if (preg_match('#/node/(\d+)#', $link, $m) === 1) {
            return $this->trackBySourceId[$m[1]] ?? null;
        }

        if (str_contains($link, 'sardegnasentieri')) {
            return $this->trackByNormalizedUrl[self::normalizeUrl($link)] ?? null;
        }

        return null;
    }

    /**
     * Normalizza un URL parlante di Sardegna Sentieri: schema ignorato, host
     * senza `www`, path minuscolo e decodificato, senza i prefissi `/en` e
     * `/index.php`, senza barra finale.
     */
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        $path = (string) ($parts['path'] ?? '');
        $path = rawurldecode($path);
        $path = mb_strtolower($path);
        $path = preg_replace('#^/en(?=/|$)#', '', $path) ?? $path;
        $path = preg_replace('#^/index\.php(?=/|$)#', '', $path) ?? $path;
        $path = rtrim($path, '/');

        return $host.$path;
    }

    private function key(string $area, string $sector, int $number, string $variant): string
    {
        return $area.'|'.$sector.'|'.$number.'|'.$variant;
    }

    private function loadTracks(): void
    {
        $table = config('wm-package.ec_track_table', 'ec_tracks');

        $rows = DB::table($table)
            ->select([
                'id',
                DB::raw("properties->'forestas'->>'source_id' as source_id"),
                DB::raw("properties->'forestas'->>'url' as url"),
            ])
            ->where(function ($query) {
                $query->whereNotNull(DB::raw("properties->'forestas'->>'source_id'"))
                    ->orWhereNotNull(DB::raw("properties->'forestas'->>'url'"));
            })
            ->get();

        $this->trackBySourceId = [];
        $this->trackByNormalizedUrl = [];

        foreach ($rows as $row) {
            if (! empty($row->source_id)) {
                $this->trackBySourceId[(string) $row->source_id] = (int) $row->id;
            }

            if (! empty($row->url)) {
                $this->trackByNormalizedUrl[self::normalizeUrl((string) $row->url)] = (int) $row->id;
            }
        }
    }

    private function loadCodes(): void
    {
        $codeClass = TrailRegistryClasses::code();
        $table = (new $codeClass)->getTable();

        $activeStatuses = array_map(fn (TrailCodeStatus $status) => $status->value, TrailCodeStatus::active());

        $rows = DB::table($table)
            ->select(['id', 'region', 'province', 'area', 'sector', 'number', 'variant', 'ec_track_id'])
            ->whereIn('status', $activeStatuses)
            ->get();

        $this->activeCodeByTrack = [];
        $this->codesByKey = [];

        foreach ($rows as $row) {
            $entry = [
                'id' => (int) $row->id,
                'code' => TrailRegistryCode::formatCode((string) $row->region, (string) $row->province, (string) $row->area, (string) $row->sector, (int) $row->number, (string) $row->variant),
                'area' => (string) $row->area,
                'sector' => (string) $row->sector,
                'number' => (int) $row->number,
                'variant' => (string) $row->variant,
                'ec_track_id' => $row->ec_track_id !== null ? (int) $row->ec_track_id : null,
            ];

            if ($entry['ec_track_id'] !== null) {
                $this->activeCodeByTrack[$entry['ec_track_id']] = $entry;
            }

            $key = $this->key($entry['area'], $entry['sector'], $entry['number'], $entry['variant']);
            $this->codesByKey[$key][] = $entry;
        }
    }

    private function loadCatastoAnomalies(): void
    {
        $anomalyClass = TrailRegistryClasses::anomaly();
        $table = (new $anomalyClass)->getTable();

        $ids = DB::table($table)
            ->where('source', TrailRegistryAnomaly::SOURCE_CATASTO)
            ->whereNotNull('ec_track_id')
            ->pluck('ec_track_id');

        $this->tracksWithCatastoAnomaly = [];

        foreach ($ids as $id) {
            $this->tracksWithCatastoAnomaly[(int) $id] = true;
        }
    }

    private function assertPrepared(): void
    {
        if ($this->codesByKey === null) {
            throw new LogicException('RegistroCatastaleMatcher::prepare() va chiamato prima di match().');
        }
    }
}
