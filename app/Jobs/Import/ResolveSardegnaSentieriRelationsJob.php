<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Risolve a fine import i collegamenti fra contenuti e li scrive in un punto solo (oc:8932).
 *
 * I job del singolo contenuto annotano in `properties.forestas.source_relations` i nid che il
 * contenuto cita. Qui si uniscono i due elenchi di Drupal (quello scritto sul percorso e quello
 * scritto sul POI) in un elenco solo e reciproco, si risolvono i nid sul database — non fra i
 * contenuti del giro, perche' l'import orario importa solo i nodi cambiati — e si scrive con un
 * `sync()` per contenuto. Un nodo mai importato si ignora. Le coppie percorso-POI oltre
 * `MAX_DISTANCE_METERS` si scartano: sono errori della redazione.
 */
class ResolveSardegnaSentieriRelationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Distanza massima fra la geometria del percorso e quella del POI. */
    public const MAX_DISTANCE_METERS = 10000;

    public const OVERLAP_KEY = 'sardegnasentieri-resolve-relations';

    public int $timeout = 1800;

    public function __construct()
    {
        $this->onQueue(ImportSardegnaSentieriTrackJob::QUEUE);
    }

    /**
     * Mai due esecuzioni insieme: `retry_after` della connessione redis (90 s) e' molto sotto il
     * `timeout`, quindi un secondo worker ri-riserverebbe il job ancora in corso. Il pivot dei
     * collegamenti non ha un indice unico e due `sync()` concorrenti inserirebbero righe doppie.
     * `dontRelease()`: il duplicato si scarta, non torna in coda; il giro orario dopo rifa'
     * comunque tutto (come `RecalculateSardegnaSentieriLayersJob`).
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::OVERLAP_KEY))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(): void
    {
        $appId = SardegnaSentieriImportService::IMPORT_APP_ID;

        $tracks = DB::table('ec_tracks')->where('app_id', $appId)
            ->selectRaw("id, properties->>'sardegnasentieri_id' as nid, properties->'forestas'->'source_relations' as rel")
            ->get();
        $pois = DB::table('ec_pois')->where('app_id', $appId)
            ->selectRaw("id, COALESCE(properties->>'out_source_feature_id', properties->>'sardegnasentieri_id') as nid, properties->'forestas'->'source_relations' as rel")
            ->get();

        $trackByNid = [];
        foreach ($tracks as $t) {
            if ($t->nid !== null) {
                $trackByNid[$t->nid] = (int) $t->id;
            }
        }
        $poiByNid = [];
        foreach ($pois as $p) {
            if ($p->nid !== null) {
                $poiByNid[$p->nid] = (int) $p->id;
            }
        }

        // Percorso => [poi => true], nell'ordine in cui il percorso li cita; poi quelli citati
        // solo dal POI.
        // Un contenuto senza `source_relations` non e' stato rimportato (l'import orario salta i
        // nodi non cambiati): il suo pivot non si svuota, perche' contiene anche i collegamenti
        // aggiunti a mano in Nova. Con la chiave presente ma vuota, invece, la fonte non ha piu'
        // collegamenti e `sync([])` e' giusto.
        $trackImported = [];
        $poiImported = [];
        $trackPois = [];
        foreach ($tracks as $t) {
            $trackImported[(int) $t->id] = $t->rel !== null;
            $trackPois[(int) $t->id] = [];
            foreach ($this->nids($t->rel, 'pois') as $nid) {
                if (isset($poiByNid[$nid])) {
                    $trackPois[(int) $t->id][$poiByNid[$nid]] = true;
                }
            }
        }
        $poiPois = [];
        foreach ($pois as $p) {
            $poiImported[(int) $p->id] = $p->rel !== null;
            $poiPois[(int) $p->id] = [];
            foreach ($this->nids($p->rel, 'pois') as $nid) {
                $id = $poiByNid[$nid] ?? null;
                if ($id !== null && $id !== (int) $p->id) {
                    $poiPois[(int) $p->id][$id] = true;
                }
            }
            foreach ($this->nids($p->rel, 'tracks') as $nid) {
                if (isset($trackByNid[$nid])) {
                    $trackPois[$trackByNid[$nid]][(int) $p->id] = true;
                }
            }
        }

        // I collegamenti fra POI sono reciproci: la compilazione in un verso solo di Drupal e'
        // un difetto della redazione, non un'informazione da conservare.
        foreach ($poiPois as $poiId => $related) {
            foreach (array_keys($related) as $relatedId) {
                $poiPois[$relatedId][$poiId] = true;
            }
        }

        $far = $this->farPairs($trackPois);

        $trackCount = 0;
        $pairCount = 0;
        foreach ($trackPois as $trackId => $poiIds) {
            $payload = [];
            $order = 0;
            foreach (array_keys($poiIds) as $poiId) {
                if (isset($far[$trackId.':'.$poiId])) {
                    continue;
                }
                $payload[$poiId] = ['order' => $order++];
            }
            $track = EcTrack::query()->find($trackId);
            if ($track === null) {
                continue;
            }
            if ($trackImported[$trackId]) {
                $track->ecPois()->sync($payload);
            } elseif ($payload !== []) {
                // Non rimportato: si aggiunge solo cio' che cita il POI, senza togliere nulla.
                $track->ecPois()->syncWithoutDetaching(array_keys($payload));
            }
            $trackCount++;
            $pairCount += count($payload);
        }

        $relatedCount = 0;
        foreach ($poiPois as $poiId => $related) {
            $poi = EcPoi::query()->find($poiId);
            if ($poi === null) {
                continue;
            }
            $ids = array_keys($related);
            if ($poiImported[$poiId]) {
                $poi->relatedPois()->sync($ids);
            } elseif ($ids !== []) {
                $poi->relatedPois()->syncWithoutDetaching($ids);
            }
            $relatedCount += count($ids);
        }

        Log::channel('import')->info("[sardegnasentieri] Collegamenti risolti: {$pairCount} coppie percorso-POI su {$trackCount} percorsi (".count($far).' oltre '.self::MAX_DISTANCE_METERS." m scartate), {$relatedCount} collegamenti POI-POI (reciproci).");
    }

    /**
     * @return list<string>
     */
    private function nids(?string $json, string $key): array
    {
        if ($json === null) {
            return [];
        }
        $decoded = json_decode($json, true);

        return array_map('strval', is_array($decoded[$key] ?? null) ? $decoded[$key] : []);
    }

    /**
     * Le coppie la cui geometria dista piu' del massimo. Il cast a geography non e'
     * facoltativo: senza, la distanza e' in gradi. Una coppia con una geometria mancante non si
     * puo' misurare e resta.
     *
     * @param  array<int, array<int, true>>  $trackPois
     * @return array<string, true> chiavi "trackId:poiId"
     */
    private function farPairs(array $trackPois): array
    {
        $pairs = [];
        foreach ($trackPois as $trackId => $poiIds) {
            foreach (array_keys($poiIds) as $poiId) {
                $pairs[] = [$trackId, $poiId];
            }
        }

        $far = [];
        foreach (array_chunk($pairs, 1000) as $chunk) {
            $values = implode(',', array_map(fn ($p) => '('.(int) $p[0].','.(int) $p[1].')', $chunk));
            $rows = DB::select(
                "SELECT t.id AS track_id, p.id AS poi_id
                 FROM (VALUES {$values}) AS v(track_id, poi_id)
                 JOIN ec_tracks t ON t.id = v.track_id
                 JOIN ec_pois p ON p.id = v.poi_id
                 WHERE ST_Distance(t.geometry::geography, p.geometry::geography) > ?",
                [self::MAX_DISTANCE_METERS]
            );
            foreach ($rows as $row) {
                $far[$row->track_id.':'.$row->poi_id] = true;
            }
        }

        return $far;
    }
}
