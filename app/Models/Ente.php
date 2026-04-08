<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoEnte;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMapTrait;
use Wm\WmPackage\Services\GeometryComputationService;

class Ente extends Model implements HasMedia
{
    use FeatureCollectionMapTrait, HasTranslations, InteractsWithMedia;

    protected $table = 'entes';

    protected $fillable = [
        'sardegnasentieri_id',
        'name',
        'description',
        'contatti',
        'pagina_web',
        'tipo_ente',
        'geometry',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
        'description' => 'array',
        'contatti' => 'array',
        'tipo_ente' => TipoEnte::class,
    ];

    public array $translatable = ['name', 'description', 'contatti'];

    public function getFeatureCollectionMap(): array
    {
        $name = is_array($this->name)
            ? ($this->name[app()->getLocale()] ?? $this->name['it'] ?? $this->name['en'] ?? reset($this->name) ?: 'Ente')
            : ($this->name ?: 'Ente');

        return $this->getFeatureCollectionMapFromTrait([
            'tooltip' => $name,
        ]);
    }

    public function setGeometryAttribute(mixed $value): void
    {
        $this->attributes['geometry'] = GeometryComputationService::make()->convertTo3DGeometry($value);
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
}
