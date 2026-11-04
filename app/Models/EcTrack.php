<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\InclusivityScore;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;

/**
 * @property Carbon|null $forestas_survey_date
 * @property int|null $forestas_inclusivity_physical
 * @property int|null $forestas_inclusivity_aids
 * @property int|null $forestas_inclusivity_digital
 */
class EcTrack extends WmEcTrack
{
    /**
     * Chiavi di `properties.forestas` che si compilano a mano in Nova: l'import le conserva.
     */
    public const MANUAL_FORESTAS_KEYS = [
        'inclusivity_independent_use',
        'inclusivity_assistance',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->translatable = array_merge($this->translatable ?? [], [
            'properties->forestas->info_utili',
            'properties->forestas->roadbook',
        ]);
    }

    protected $fillable = [
        'name',
        'geometry',
        'app_id',
        'properties',
        'user_id',
        'osmid',
        'stato_validazione',
        'forestas_registry_code',
        'forestas_survey_date',
        'forestas_inclusivity_physical',
        'forestas_inclusivity_aids',
        'forestas_inclusivity_digital',
    ];

    /**
     * Le colonne di Forestas ri-esposte piatte nel `properties` del GeoJSON, cosi' le chiavi
     * spostate fuori da `properties` non spariscono dall'API (oc:8910). Le colonne vuote non
     * compaiono. Lo legge `GeoJsonService::getModelAsGeojson()` del package.
     *
     * @return array<string, mixed>
     */
    public function geojsonFlatProperties(): array
    {
        return array_filter([
            'forestas_registry_code' => $this->forestas_registry_code,
            'forestas_survey_date' => $this->forestas_survey_date?->format('Y-m-d'),
            'forestas_inclusivity_physical' => $this->forestas_inclusivity_physical,
            'forestas_inclusivity_aids' => $this->forestas_inclusivity_aids,
            'forestas_inclusivity_digital' => $this->forestas_inclusivity_digital,
        ], fn ($value) => $value !== null);
    }

    /**
     * Aggiunge punteggio e classe di inclusività: derivati, non in colonna. Se i tre valori non
     * sono tutti presenti le chiavi restano null (sentiero non misurato).
     */
    public function toSearchableArray()
    {
        $inclusivity = InclusivityScore::for($this);

        return array_merge(parent::toSearchableArray(), [
            'inclusivity_score' => $inclusivity['score'] ?? null,
            'inclusivity_class' => $inclusivity['class'] ?? null,
        ]);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'forestas_survey_date' => 'date',
            'forestas_inclusivity_physical' => 'integer',
            'forestas_inclusivity_aids' => 'integer',
            'forestas_inclusivity_digital' => 'integer',
        ]);
    }

    public function enti(): MorphToMany
    {
        return $this->morphToMany(Ente::class, 'enteable')
            ->using(Enteable::class)
            ->withPivot('ruolo');
    }
}
