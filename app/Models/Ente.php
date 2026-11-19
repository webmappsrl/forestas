<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoEnte;
use App\Search\EnteSearchIndex;
use App\Services\Import\SardegnaSentieriImportService;
use App\Support\EnteHtmlSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Http\Resources\MediaResource;
use Wm\WmPackage\Models\EcPoi as WmEcPoi;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMapTrait;
use Wm\WmPackage\Services\GeometryComputationService;
use Wm\WmPackage\Services\Models\MediaService;

class Ente extends Model implements HasMedia
{
    use FeatureCollectionMapTrait, HasTranslations, InteractsWithMedia;
    use Searchable {
        searchable as protected scoutSearchable;
        unsearchable as protected scoutUnsearchable;
    }

    protected $table = 'entes';

    protected $fillable = [
        'sardegnasentieri_id',
        'name',
        'description',
        'contatti',
        'pagina_web',
        'pagina_web_title',
        'slug',
        'drupal_created_at',
        'tipo_ente',
        'geometry',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
        'description' => 'array',
        'contatti' => 'array',
        'drupal_created_at' => 'datetime',
        'tipo_ente' => TipoEnte::class,
    ];

    public array $translatable = ['name', 'description', 'contatti'];

    public function getFeatureCollectionMap(): array
    {
        // HasTranslations risolve `name` nella locale corrente: qui e' una stringa,
        // mai la mappa di traduzioni grezza.
        $name = $this->name ?: 'Ente';

        return $this->getFeatureCollectionMapFromTrait([
            'tooltip' => $name,
        ]);
    }

    public function setGeometryAttribute(mixed $value): void
    {
        $this->attributes['geometry'] = GeometryComputationService::make()->convertTo3DGeometry($value);
    }

    /**
     * Descrizione e contatti passano sempre dalla whitelist HTML (oc:8965). Mutator e non evento
     * `saving`: l'import salva con `saveQuietly()`, che gli eventi li salta. Con i campi tradotti
     * `HasTranslations::setTranslation()` chiama il mutator per ogni lingua.
     */
    public function setDescriptionAttribute(?string $value): void
    {
        $this->attributes['description'] = EnteHtmlSanitizer::clean($value);
    }

    public function setContattiAttribute(?string $value): void
    {
        $this->attributes['contatti'] = EnteHtmlSanitizer::clean($value);
    }

    public function getAppIdAttribute(): int
    {
        return SardegnaSentieriImportService::IMPORT_APP_ID;
    }

    public function ecTracks(): MorphToMany
    {
        return $this->morphedByMany(EcTrack::class, 'enteable', 'enteables', 'ente_id')
            ->using(Enteable::class)
            ->withPivot('ruolo');
    }

    public function ecTracksGestore(): MorphToMany
    {
        return $this->ecTracks()->wherePivot('ruolo', 'gestore');
    }

    public function ecTracksManutentore(): MorphToMany
    {
        return $this->ecTracks()->wherePivot('ruolo', 'manutentore');
    }

    public function ecTracksRilevatore(): MorphToMany
    {
        return $this->ecTracks()->wherePivot('ruolo', 'rilevatore');
    }

    public function ecTracksOperatore(): MorphToMany
    {
        return $this->ecTracks()->wherePivot('ruolo', 'operatore');
    }

    public function ecTracksComplessoForestale(): MorphToMany
    {
        return $this->ecTracks()->wherePivot('ruolo', 'complesso_forestale');
    }

    /**
     * POI collegati all'ente (oc:8965). L'import scrive le righe dei POI in `enteables` con il
     * tipo del package, quindi la relazione filtra su quello: è lo specchio di `EcPoi::enti()`,
     * da cui dipende il dettaglio del POI (oc:8959).
     */
    public function ecPois(): BelongsToMany
    {
        return $this->belongsToMany(EcPoi::class, 'enteables', 'ente_id', 'enteable_id')
            ->wherePivot('enteable_type', WmEcPoi::class)
            ->withPivot('ruolo');
    }

    /**
     * Enti ancora presenti in Drupal (oc:8965): l'import non cancella quelli spariti, li marca
     * con `properties.forestas.deleted_from_source`. Usato dal JSON degli enti e dalla ricerca.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereRaw("(properties->'forestas'->>'deleted_from_source') IS DISTINCT FROM 'true'");
    }

    /**
     * Ricerca degli enti per il sito WordPress (oc:8965): indice proprio, mapping in
     * EnteSearchIndex. L'import salva con `saveQuietly()`, quindi aggiorna l'indice da sé.
     */
    /**
     * Lo slug dell'ente: l'URL della sua pagina sul sito WordPress (oc:8965). Solo ASCII, perché
     * il sito costruisce gli URL con sanitize_title(), che toglie gli accenti; se manca, dal nome.
     * Unica regola per import, Nova e confronto con Drupal.
     */
    public static function normalizeSlug(?string $slug, ?string $name): ?string
    {
        $normalized = Str::slug((string) $slug) ?: Str::slug((string) $name);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Aggiorna l'indice senza far fallire chi salva (oc:8965): con `scout.queue` spento la
     * chiamata a Elasticsearch è sincrona, e un errore farebbe fallire il salvataggio da Nova o
     * l'import. Come TaxonomyTermableObserver del package: si registra e l'indice si riallinea
     * al prossimo salvataggio o con scout:import.
     */
    public function searchable(): void
    {
        try {
            $this->scoutSearchable();
        } catch (\Throwable $e) {
            Log::warning("Indice degli enti non aggiornato per l'ente {$this->id}: {$e->getMessage()}");
        }
    }

    public function unsearchable(): void
    {
        try {
            $this->scoutUnsearchable();
        } catch (\Throwable $e) {
            Log::warning("Ente {$this->id} non tolto dall'indice degli enti: {$e->getMessage()}");
        }
    }

    public function searchableAs(): string
    {
        return EnteSearchIndex::NAME;
    }

    public function shouldBeSearchable(): bool
    {
        $properties = is_array($this->properties) ? $this->properties : [];

        return ($properties['forestas']['deleted_from_source'] ?? false) !== true;
    }

    /**
     * Il documento della ricerca: i campi da cercare e quelli della card. Mai i contatti, che
     * hanno nomi ed email di persone.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $image = $this->getMedia('default')->first();
        $description = $this->getTranslation('description', 'it', false);

        return [
            'id' => (int) $this->id,
            'slug' => $this->slug,
            'type' => $this->tipoEnteValue(),
            'name' => $this->getTranslation('name', 'it', false),
            'name_translations' => $this->getTranslations('name'),
            'description' => self::plainText($description),
            'feature_image' => $image === null ? null : array_intersect_key(
                (new MediaResource($image))->resolve(),
                array_flip(['url', 'thumbnail'])
            ),
        ];
    }

    /**
     * Il tipo di ente come valore grezzo (oc:8965). L'import salva apposta un valore fuori da
     * TipoEnte quando un termine di Drupal non è mappato: il cast dell'enum lancerebbe
     * ValueError e manderebbe in errore il JSON di tutti gli enti.
     */
    public function tipoEnteValue(): ?string
    {
        $raw = $this->getAttributes()['tipo_ente'] ?? null;

        return $raw instanceof TipoEnte ? $raw->value : ($raw === null ? null : (string) $raw);
    }

    /**
     * Testo semplice da HTML, con i blocchi separati da uno spazio: `<h4>Il GAL</h4><p>Testo`
     * diventa `Il GAL Testo`, non `Il GALTesto`.
     */
    private static function plainText(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }
        $text = html_entity_decode(strip_tags(preg_replace('/<[^>]+>/', ' $0 ', $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);

        return $text === '' ? null : $text;
    }

    /**
     * @param  Builder<Ente>  $query
     * @return Builder<Ente>
     */
    protected function makeAllSearchableUsing($query)
    {
        return $query->with('media');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('default');
    }

    /**
     * Miniature delle immagini dell'ente (oc:8965), come quelle dei modelli del package:
     * `Ente` non estende `GeometryModel`, quindi le conversioni vanno registrate qui.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $mediaService = MediaService::make();
        foreach ($mediaService->getThumbnailSizes() as $size) {
            $this
                ->addMediaConversion(
                    $mediaService->getMediaConversionNameByWidthAndHeight($size['width'], $size['height'])
                )
                ->performOnCollections('default')
                ->queued()
                ->fit(Fit::Crop, $size['width'], $size['height']);
        }
    }
}
