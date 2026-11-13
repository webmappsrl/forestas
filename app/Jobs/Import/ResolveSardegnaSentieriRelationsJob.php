<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Models\EcPoi;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Risolve a fine import i collegamenti fra POI di Drupal (oc:8932).
 *
 * I job del singolo contenuto annotano in `properties.forestas.source_relations` i nid che il
 * contenuto cita. Qui si risolvono i nid dei POI correlati sul database — non fra i contenuti del
 * giro, perche' l'import orario importa solo i nodi cambiati —, si rendono reciproci e si scrivono
 * con un `sync()` per POI. Un nodo mai importato si ignora.
 *
 * I collegamenti fra POI e percorsi di Drupal non si scrivono piu': `ec_poi_ec_track` si calcola
 * per vicinanza (`CalculatePoiTracksChunkJob`, oc:8959), come i sentieri correlati fra tracce.
 */
class ResolveSardegnaSentieriRelationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

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

        $pois = DB::table('ec_pois')->where('app_id', $appId)
            ->selectRaw("id, COALESCE(properties->>'out_source_feature_id', properties->>'sardegnasentieri_id') as nid, properties->'forestas'->'source_relations' as rel")
            ->get();

        $poiByNid = [];
        foreach ($pois as $p) {
            if ($p->nid !== null) {
                $poiByNid[$p->nid] = (int) $p->id;
            }
        }

        // Un POI senza `source_relations` non e' stato rimportato (l'import orario salta i nodi
        // non cambiati): i suoi collegamenti non si svuotano. Con la chiave presente ma vuota la
        // fonte non ha piu' collegamenti e `sync([])` e' giusto.
        $poiImported = [];
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
        }

        // I collegamenti fra POI sono reciproci: la compilazione in un verso solo di Drupal e'
        // un difetto della redazione, non un'informazione da conservare.
        foreach ($poiPois as $poiId => $related) {
            foreach (array_keys($related) as $relatedId) {
                $poiPois[$relatedId][$poiId] = true;
            }
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

        Log::channel('import')->info("[sardegnasentieri] Collegamenti risolti: {$relatedCount} collegamenti POI-POI (reciproci).");
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
}
