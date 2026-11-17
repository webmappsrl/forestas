<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EnteResource;
use App\Models\Ente;
use App\Search\EnteSearch;
use App\Support\DeletedFromSource;
use App\Support\ForestasDetailParts;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gli enti (oc:8965), dietro VerifyWpApiToken: oggi li chiama il sito WordPress.
 */
class EnteController extends Controller
{
    /**
     * Le sole chiavi di `ec_tracks.properties` che servono al JSON degli enti (oc:8965).
     */
    private const TRACK_PROPERTIES = "jsonb_build_object(
        'ref', ec_tracks.properties->'ref',
        'dem_data', ec_tracks.properties->'dem_data',
        'osm_data', ec_tracks.properties->'osm_data',
        'manual_data', ec_tracks.properties->'manual_data',
        'forestas', jsonb_build_object('created_at', ec_tracks.properties->'forestas'->'created_at')
    ) AS properties";

    /**
     * Tutti gli enti ancora presenti in Drupal, in un'unica risorsa che il sito tiene in cache.
     * `generated_at` dice di quando è il dato: su UAT il reset notturno ricrea le tracce con id
     * nuovi, e per qualche ora dopo le 06:00 i collegamenti sono parziali.
     */
    public function index(): JsonResponse
    {
        $entes = Ente::active()
            ->select('entes.*')
            ->selectRaw('ST_X(geometry::geometry) AS longitude, ST_Y(geometry::geometry) AS latitude')
            ->with([
                'media',
                // Solo ciò che il JSON usa: geometrie e properties intere delle tracce collegate
                // pesano decine di MB (review oc:8965). Di `properties` resta una copia ridotta
                // con le chiavi lette dalla card dei sentieri gestiti (codice, lunghezza e tempo)
                // e la data di creazione su Drupal.
                'ecTracks' => fn (Relation $query) => $query
                    ->select('ec_tracks.id', 'ec_tracks.name', 'ec_tracks.osmid')
                    ->selectRaw(self::TRACK_PROPERTIES)
                    ->whereRaw(DeletedFromSource::notDeletedSql('ec_tracks'))
                    ->orderBy('ec_tracks.id'),
                ...array_map(fn (string $relation) => "ecTracks.{$relation}", ForestasDetailParts::TRACK_CARD_RELATIONS),
                'ecPois' => fn (Relation $query) => $query
                    ->select('ec_pois.id', 'ec_pois.name')
                    ->whereRaw(DeletedFromSource::notDeletedSql('ec_pois'))
                    ->orderBy('ec_pois.id'),
            ])
            ->orderBy('entes.id')
            ->get();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'data' => EnteResource::collection($entes)->resolve(),
        ]);
    }

    /**
     * Ricerca degli enti per la ricerca unica del sito: le card (id, slug, nome, tipo, immagine)
     * in ordine di rilevanza, prese dai documenti dell'indice.
     */
    public function search(Request $request, EnteSearch $search): JsonResponse
    {
        $validated = $request->validate(['query' => ['required', 'string', 'max:200']]);

        try {
            $sources = $search->run($validated['query']);
        } catch (ClientResponseException $e) {
            // Indice mai creato: il primo deploy richiede uno scout:import degli enti.
            if ($e->getCode() === 404) {
                return response()->json(['message' => 'Indice degli enti assente: lanciare scout:import "App\\Models\\Ente".'], 503);
            }
            throw $e;
        }

        return response()->json(['data' => array_map(fn (array $source) => [
            'id' => (int) $source['id'],
            'slug' => $source['slug'] ?? null,
            'name' => $source['name_translations'] ?? [],
            'type' => $source['type'] ?? null,
            'feature_image' => $source['feature_image'] ?? null,
        ], $sources)]);
    }
}
