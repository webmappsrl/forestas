<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EcPoi;
use App\Support\ForestasPoiDetail;
use Illuminate\Http\JsonResponse;
use Wm\WmPackage\Services\DetailTaxonomiesService;

/**
 * Il dettaglio pubblico del POI, `api/ec/poi/{id}` (oc:8959). Sostituisce la rotta del package
 * (`default.api.ec.poi.json`), che carica sempre `Wm\WmPackage\Models\EcPoi` e non lascia allo
 * shard un punto in cui aggiungere i dati di Forestas. La risposta parte dal GeoJSON del package
 * (`getGeojson()`) e aggiunge nome, `taxonomies` e `properties.forestas` costruito in uscita.
 * Le altre rotte dei POI (`{id}.geojson`, download, `api/v2/…`) restano del package.
 */
class EcPoiDetailController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $poi = EcPoi::query()->find($id);
        if ($poi === null) {
            return response()->json(['code' => 404, 'error' => 'Not found'], 404);
        }

        $geojson = $poi->getGeojson();
        $properties = (array) ($geojson['properties'] ?? []);

        $properties['name'] = $poi->getTranslations('name');
        // Le tassonomie nella stessa forma di `pois.geojson` e del dettaglio delle tracce.
        $properties['taxonomies'] = app(DetailTaxonomiesService::class)
            ->buildForJson($poi, 'EcPoi', (int) $poi->app_id);
        $forestas = ForestasPoiDetail::build($poi);
        // Vuoto resta un oggetto: un array PHP vuoto diventerebbe `[]` in JSON.
        $properties['forestas'] = $forestas === [] ? new \stdClass : $forestas;
        // Il nid di Drupal esce in `forestas.sardegnasentieri_id`, con lo stesso nome delle tracce;
        // i layer riguardano le app, non la scheda.
        unset($properties['out_source_feature_id'], $properties['sardegnasentieri_id'], $properties['layers']);

        $geojson['properties'] = $properties;

        return response()->json($geojson);
    }
}
