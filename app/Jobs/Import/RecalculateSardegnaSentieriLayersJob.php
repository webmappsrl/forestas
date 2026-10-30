<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Layerable;
use Wm\WmPackage\Services\Models\LayerService;
use Wm\WmPackage\Services\PBFGeneratorService;

/**
 * Ricalcola, a fine import da Sardegna Sentieri, i layer automatici dell'app (oc:8827).
 *
 * Per ogni layer dell'app di import in `track_mode` auto con almeno un'attività o un'area
 * (`taxonomyWheres`) riallinea `layerables` alle tracce che rispettano il filtro. I POI si
 * assegnano solo ai layer delle aree catastali; sui layer di tipologia con `poi_mode` auto il
 * pivot dei POI si svuota, qualunque attività portino. Poi riallinea sempre `properties.layers`
 * delle feature (a diff: salva solo quelle diverse). Dal confronto del pivot con il giro prima
 * dipendono solo geometria e PBF: si accodano una volta per layer, se il pivot o
 * `properties.layers` di qualche feature sono cambiati (`regeneratePbfsForLayer()` su un layer
 * vuoto rigenera i PBF dell'intera app, e il job gira a ogni batch orario). Se un tentativo muore
 * dopo il pivot, il retry ripara comunque `properties.layers` e accoda geometria e PBF.
 *
 * Un errore negli effetti di un layer si logga e non si rilancia: il job sta in catena prima del
 * reindex e dei file AWS, che `Bus::chain()` non farebbe partire. Il giro dopo ripara
 * `properties.layers`; i PBF si rigenerano solo se cambia di nuovo qualcosa.
 *
 * Senza eventi: il pivot `Layerable` ha un observer che accoda una catena PBF e un job di
 * geometria per OGNI riga creata o cancellata, migliaia di job a notte. Le scritture del pivot
 * stanno in `Layerable::withoutEvents()` (che spegne il dispatcher di tutti i model: dentro solo
 * le chiamate che scrivono il pivot) e gli effetti si fanno una volta per layer, dopo.
 *
 * Costo accettato: `sync()` del package riscrive `created_at`/`updated_at` di tutte le righe già
 * presenti a ogni giro; gli id restano gli stessi.
 *
 * Prima del reindex: il reindex di `RegenerateTaxonomyWhereOutputsJob` legge
 * `properties.layers`, quindi qui si aggiorna in modo sincrono e non con
 * `UpdateLayeredFeaturesJob`, che il package accoda in ritardo.
 *
 * Mai due esecuzioni insieme: `layerables` non ha un indice unico e due `sync()` concorrenti
 * inserirebbero righe doppie.
 */
class RecalculateSardegnaSentieriLayersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const OVERLAP_KEY = 'sardegnasentieri-recalculate-layers';

    /**
     * Sotto il `timeout` del supervisor `supervisor-sardegnasentieri-import`
     * (360 in config/horizon.php), come gli altri job della coda di import.
     */
    public int $timeout = 300;

    /** Il ricalcolo è idempotente: rilanciarlo produce le stesse righe. */
    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue(ImportSardegnaSentieriTrackJob::QUEUE);
    }

    /**
     * Se un altro ricalcolo è in corso questo si salta (`dontRelease()`): quello in corso ricalcola
     * gli stessi layer. Un `release()` consumerebbe un tentativo (`$tries = 2`) e, finiti i
     * tentativi, il job fallirebbe fermando la catena prima del reindex; così invece la catena
     * prosegue (CallQueuedHandler::call(), ensureNextJobInChainIsDispatched).
     *
     * Il lock passa dalla cache di default: il middleware gira nel worker, fuori da transazioni,
     * quindi non c'è il problema del lock `database` dentro una transazione (25P02).
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

    public function handle(LayerService $layerService, PBFGeneratorService $pbfGeneratorService): void
    {
        foreach ($this->autoLayers() as $layer) {
            $prima = $this->membership($layer);

            $this->writePivot($layerService, $layer);

            $pivotCambiato = $this->membership($layer) !== $prima;

            try {
                $featureCambiate = $this->alignFeatures($layerService, $layer);

                if (! $pivotCambiato && ! $featureCambiate) {
                    continue;
                }

                $layerService->updateLayerGeometryWithJob($layer);
                $pbfGeneratorService->regeneratePbfsForLayer($layer);

                Log::channel('import')->info('Layer ricalcolato a fine import Sardegna Sentieri', ['layer_id' => $layer->id]);
            } catch (Throwable $e) {
                Log::channel('import')->error('Ricalcolo del layer fallito a fine import Sardegna Sentieri', [
                    'layer_id' => $layer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Riscrive il pivot del layer, senza eventi. Spec oc:8827: POI solo sui layer delle aree
     * catastali; svuotati su quelli di tipologia, a meno che i POI siano gestiti a mano.
     */
    private function writePivot(LayerService $layerService, Layer $layer): void
    {
        $haAree = $layer->taxonomyWheres->isNotEmpty();

        Layerable::withoutEvents(function () use ($layerService, $layer, $haAree) {
            $layerService->assignTracksByTaxonomy($layer);

            if ($haAree) {
                $layerService->assignPoisByTaxonomy($layer);
            } elseif ($layer->isAutoPoiMode()) {
                $layer->ecPois()->sync([]);
            }
        });
    }

    /**
     * Riallinea `properties.layers` di tracce e POI al pivot appena scritto.
     *
     * @return bool se qualche feature è cambiata
     */
    private function alignFeatures(LayerService $layerService, Layer $layer): bool
    {
        // Le relazioni del pivot appena riscritto non devono arrivare dalla cache del model.
        $layer->unsetRelation('ecTracks')->unsetRelation('ecPois');

        $trackModelClass = config('wm-package.ec_track_model', 'App\Models\EcTrack');
        $cambiate = $this->haCambiato($layerService->updateLayersPropertyOnLayeredFeature($layer, $trackModelClass));

        if ($layer->taxonomyWheres->isNotEmpty()) {
            return $this->haCambiato($layerService->updateLayersPropertyOnLayeredFeature($layer, EcPoi::class)) || $cambiate;
        }

        // Con i POI manuali il pivot non è nostro: non si tocca nemmeno `properties.layers`.
        if ($layer->isAutoPoiMode()) {
            return $this->removeLayerFromPois($layer) > 0 || $cambiate;
        }

        return $cambiate;
    }

    /**
     * Se `updateLayersPropertyOnLayeredFeature()` ha aggiunto o tolto il layer da qualche feature.
     *
     * @param  array{added: array<int>, deleted: array<int>}  $esito
     */
    private function haCambiato(array $esito): bool
    {
        return $esito['added'] !== [] || $esito['deleted'] !== [];
    }

    /**
     * Toglie il layer da `properties.layers` dei POI, sui layer di tipologia.
     *
     * Non si usa `updateLayersPropertyOnLayeredFeature()`: con il pivot dei POI vuoto il package
     * ricade sul filtro per attività (`onLayer()`) e scriverebbe il layer sui POI che ne portano
     * l'attività, contro la regola «POI vuoti sui layer di tipologia» (oc:8827).
     *
     * @return int quanti POI sono stati modificati
     */
    private function removeLayerFromPois(Layer $layer): int
    {
        $tolti = 0;

        EcPoi::query()
            ->whereRaw("properties->'layers' @> ?::jsonb", [json_encode([$layer->id])])
            ->lazyById()
            ->each(function (EcPoi $poi) use ($layer, &$tolti) {
                $properties = $poi->properties;
                $properties['layers'] = array_values(array_diff($properties['layers'], [$layer->id]));
                $poi->properties = $properties;
                $poi->saveQuietly();
                $tolti++;
            });

        return $tolti;
    }

    /**
     * Composizione del layer: le coppie tipo/id delle sue righe in `layerables`, ordinate.
     * Basta a dire se `properties.layers`, geometria e PBF vanno ricalcolati: dipendono solo
     * da quali feature appartengono al layer.
     *
     * @return list<string>
     */
    private function membership(Layer $layer): array
    {
        return DB::table('layerables')
            ->where('layer_id', $layer->id)
            ->orderBy('layerable_type')
            ->orderBy('layerable_id')
            ->get(['layerable_type', 'layerable_id'])
            ->map(fn (object $riga) => $riga->layerable_type.':'.$riga->layerable_id)
            ->all();
    }

    /**
     * Layer dell'app di import, propri o associati, in `track_mode` auto e con almeno un filtro:
     * un layer auto senza filtro verrebbe svuotato da `assignTracksByTaxonomy()`.
     *
     * @return iterable<Layer>
     */
    private function autoLayers(): iterable
    {
        $appId = SardegnaSentieriImportService::IMPORT_APP_ID;

        // Le app associate si leggono dal pivot `layer_associated_app` (la relazione
        // `associatedApps()` del package non dichiara il tipo di ritorno e Larastan non la vede).

        return Layer::query()
            ->where(function (Builder $query) use ($appId) {
                $query->where('app_id', $appId)
                    ->orWhereIn('id', DB::table('layer_associated_app')->select('layer_id')->where('app_id', $appId));
            })
            ->where(function (Builder $query) {
                $query->whereHas('taxonomyActivities')
                    ->orWhereHas('taxonomyWheres');
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Layer $layer) => $layer->isAutoTrackMode());
    }
}
