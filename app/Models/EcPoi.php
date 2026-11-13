<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;
use Wm\WmPackage\Models\EcPoi as WmEcPoi;

class EcPoi extends WmEcPoi
{
    private const FORESTAS_COME_ARRIVARE_KEY = 'properties->forestas->come_arrivare';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->translatable = array_merge($this->translatable ?? [], [
            self::FORESTAS_COME_ARRIVARE_KEY,
        ]);
    }

    public function getTranslations(?string $key = null, ?array $allowedLocales = null): array
    {
        // Spatie si aspetta sempre un array per le nested translations.
        // Dati legacy possono avere `properties.forestas.come_arrivare = null`.
        if ($key === self::FORESTAS_COME_ARRIVARE_KEY) {
            $value = Arr::get((array) ($this->properties ?? []), 'forestas.come_arrivare', []);

            if (! is_array($value)) {
                return [];
            }

            return array_filter(
                $value,
                fn ($translation) => $translation !== null && $translation !== ''
            );
        }

        return parent::getTranslations($key, $allowedLocales);
    }

    /**
     * I percorsi collegati al POI in `ec_poi_ec_track`, calcolati per vicinanza entro
     * `sardegnasentieri.poi_tracks_radius_meters` da `CalculatePoiTracksChunkJob` (oc:8959), dal
     * più vicino: `distance_from_poi` è la distanza in metri dal tratto più vicino, calcolata nella
     * query sulle colonne `geography`. Nel dettaglio del POI sono i suoi `nearby_tracks`.
     */
    public function nearbyTracks(): BelongsToMany
    {
        return $this->belongsToMany(EcTrack::class, 'ec_poi_ec_track', 'ec_poi_id', 'ec_track_id')
            ->select('ec_tracks.*')
            ->selectRaw('ST_Distance(ec_tracks.geometry::geography, (SELECT p.geometry FROM ec_pois p WHERE p.id = ec_poi_ec_track.ec_poi_id)::geography) AS distance_from_poi')
            ->orderBy('distance_from_poi')
            ->orderBy('ec_tracks.id');
    }

    /**
     * Gli enti collegati al POI, con il pivot `ruolo`. L'import li scrive in `enteables` con il
     * tipo `Wm\WmPackage\Models\EcPoi` (SardegnaSentieriImportService), non con il morph type
     * `App\Models\EcPoi`: la relazione legge quel tipo (oc:8959).
     */
    public function enti(): BelongsToMany
    {
        return $this->belongsToMany(Ente::class, 'enteables', 'enteable_id', 'ente_id')
            ->wherePivot('enteable_type', WmEcPoi::class)
            ->withPivot('ruolo');
    }

    public function relatedPois(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'ec_poi_related_pois',
            'ec_poi_id',
            'related_poi_id'
        );
    }
}
