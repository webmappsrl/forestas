<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\FeatureCollection\GenerateFeatureCollectionJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\FeatureCollection;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Services\Models\LayerService;

/**
 * Il passo delle aree geografiche dell'import (oc:9001): le 39 aree di Geohub diventano Taxonomy
 * Where, un Layer ciascuna e l'overlay `aree_geografiche` in modalità `generated`, con lo stesso
 * percorso con cui sono nate le aree catastali, ma senza passi a mano.
 *
 * I layer nascono vuoti (eventi del pivot spenti) e li riempie `RecalculateSardegnaSentieriLayersJob`,
 * come quelli delle catastali: con gli eventi accesi, a tracce già presenti, partirebbero un job di
 * geometria e una catena PBF per ogni riga del pivot. Contengono solo percorsi, come su Geohub: la
 * modalità dei POI è manuale, altrimenti le aree con soli POI farebbero rigenerare ogni notte i
 * PBF dell'intera app.
 */
class GeographicAreasImportService
{
    public const FEATURE_COLLECTION_NAME = 'aree_geografiche';

    public function __construct(private readonly TaxonomyWhereLayersImportService $areasImport) {}

    /**
     * @return array{areas: array<string, int>, layers_created: int}
     */
    public function run(): array
    {
        $appId = SardegnaSentieriImportService::IMPORT_APP_ID;
        $sourceKey = TaxonomyWhereLayersImportService::GEOGRAPHIC_AREAS_SOURCE_KEY;

        $areas = $this->areasImport->import($appId, $sourceKey);

        $app = App::query()->findOrFail($appId);
        $layerService = LayerService::make();
        $layerIds = [];
        $layersCreated = 0;

        $wheres = TaxonomyWhere::query()->where('properties->source', $sourceKey)->orderBy('id')->get();
        foreach ($wheres as $where) {
            $existing = Layer::query()
                ->whereHas('taxonomyWheres', fn ($query) => $query->whereKey($where->id))
                ->pluck('id');

            if ($existing->isNotEmpty()) {
                $this->ensureManualPoiMode($existing->all());
                $layerIds = [...$layerIds, ...$existing->all()];

                continue;
            }

            $layer = $layerService->createLayerFromTaxonomyWhere($where, $app, withPivotEvents: false);
            $layer->setPoiMode('manual');
            $layerIds[] = $layer->id;
            $layersCreated++;
        }

        // Nessun layer (per esempio `32.json` cambiato e tutte le aree saltate): la generazione
        // sovrascriverebbe il file dell'overlay con uno vuoto, e l'overlay sparirebbe in silenzio.
        if ($layerIds === []) {
            Log::channel('import')->warning('Aree geografiche senza layer: overlay non toccato.', $areas);
        } else {
            $this->linkFeatureCollection($appId, $layerIds, $layersCreated > 0);
        }

        return ['areas' => $areas, 'layers_created' => $layersCreated];
    }

    /**
     * Un giro interrotto fra la creazione di un layer e `setPoiMode()` lo lascerebbe con i POI
     * automatici: il ricalcolo ci metterebbe i POI e rigenererebbe i PBF ogni notte. Si rimette
     * in manuale solo un layer senza modalità, non uno a cui qualcuno l'ha cambiata in Nova.
     *
     * @param  list<int>  $layerIds
     */
    private function ensureManualPoiMode(array $layerIds): void
    {
        Layer::query()
            ->whereKey($layerIds)
            ->whereRaw("(configuration->>'poi_mode') IS NULL")
            ->get()
            ->each(fn (Layer $layer) => $layer->setPoiMode('manual'));
    }

    /**
     * Collega i layer all'overlay delle aree geografiche e lo porta a `generated`, creandolo se
     * manca con i valori che ha su Geohub. Si salva senza eventi e la generazione si accoda dopo
     * il collegamento: l'observer del modello la accoderebbe al salvataggio, prima dei layer.
     *
     * @param  list<int>  $layerIds
     */
    private function linkFeatureCollection(int $appId, array $layerIds, bool $layersCreated): void
    {
        $fc = FeatureCollection::query()
            ->where('app_id', $appId)
            ->where('name', self::FEATURE_COLLECTION_NAME)
            ->first();

        $changed = $layersCreated;

        if ($fc === null) {
            // Nasce fuori dagli overlay dell'App: su un ambiente nuovo va aggiunta a mano a
            // `config_overlays`, come le altre (vedi la pagina di conoscenza).
            $fc = new FeatureCollection([
                'app_id' => $appId,
                'name' => self::FEATURE_COLLECTION_NAME,
                'label' => ['it' => 'Aree geografiche', 'en' => 'Geographical areas'],
                'enabled' => true,
                'clickable' => true,
                'mode' => 'generated',
                'fill_color' => '#a61c0066',
                'stroke_color' => '#a61c00',
                'stroke_width' => 2,
            ]);
            $fc->saveQuietly();
            $changed = true;
        } elseif ($fc->mode !== 'generated') {
            $fc->mode = 'generated';
            $fc->saveQuietly();
            $changed = true;
        }

        $fc->layers()->syncWithoutDetaching($layerIds);

        if ($changed) {
            // Rigenera il geojson con i `layer_id` dei layer dello shard e, con l'overlay
            // abilitato, il config.json dell'App (FeatureCollectionService::generateAndStore()).
            GenerateFeatureCollectionJob::dispatch($fc->id);
        }
    }
}
