<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ForestasTrackDetail;
use App\Support\InclusivityScore;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * Identifier della famiglia dello stato di validazione (oc:8932): era la colonna
     * `stato_validazione`. Si legge con `$track->term(self::VALIDATION_STATUS_TAXONOMY)`.
     */
    public const VALIDATION_STATUS_TAXONOMY = 'validation-status';

    /**
     * L'identifier del termine dello stato di validazione, come attributo del modello.
     *
     * Serve a Nova: un `Select` con `displayUsingLabels()` legge l'attributo del modello e
     * ignora il `resolveUsing()` del campo (`Field::resolveForDisplay()`), quindi senza questo
     * accessore la scheda mostrava sempre un trattino anche con il termine assegnato. Scriverlo
     * non fa niente: il valore vive in `taxonomy_termables`, e a salvarlo e' il `fillUsing()`
     * del campo (oc:8932).
     */
    public function getValidationStatusAttribute(): ?string
    {
        return $this->exists ? $this->term(self::VALIDATION_STATUS_TAXONOMY)?->identifier : null;
    }

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
        ]);
    }

    protected $fillable = [
        'name',
        'geometry',
        'app_id',
        'properties',
        'user_id',
        'osmid',
        'forestas_registry_code',
        'forestas_survey_date',
        'forestas_inclusivity_physical',
        'forestas_inclusivity_aids',
        'forestas_inclusivity_digital',
    ];

    /**
     * Il dettaglio pubblico della traccia con il `forestas` costruito in uscita
     * (`ForestasTrackDetail`, oc:8959) al posto di quello salvato nel DB, che ha anche id di Drupal
     * e chiavi interne dell'import; senza l'id del nodo Drupal.
     *
     * Codice del registro, data del rilievo e inclusività stanno dentro `forestas` e non più piatti
     * in `properties` («i dati propri di Forestas restano sotto forestas»): per questo non c'è più
     * `geojsonFlatProperties()`, che li ri-esponeva fuori (oc:8910) e che il file su AWS non ha mai
     * usato finché `EC_TRACK_MODEL` puntava al modello del package.
     */
    public function getDetailGeojson(): ?array
    {
        $geojson = parent::getDetailGeojson();
        if (! is_array($geojson) || ! isset($geojson['properties']) || ! is_array($geojson['properties'])) {
            return $geojson;
        }

        $forestas = ForestasTrackDetail::build($this);
        // Vuoto resta un oggetto: un array PHP vuoto diventerebbe `[]` in JSON.
        $geojson['properties']['forestas'] = $forestas === [] ? new \stdClass : $forestas;
        // L'id del nodo Drupal serve all'import e a Nova, non al frontend: Drupal verrà spento.
        unset($geojson['properties']['sardegnasentieri_id']);

        return $geojson;
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

    /**
     * Sentieri vicini, calcolati dalla geometria da CalculateNearbyTracksJob (oc:8932).
     * La vicinanza e' simmetrica: ogni coppia ha due righe, una per verso, quindi la relazione
     * funziona da entrambe le parti. Sola lettura: il job riscrive la tabella a ogni import.
     */
    public function relatedTracks(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'ec_track_related_tracks',
            'ec_track_id',
            'related_track_id'
        );
    }
}
