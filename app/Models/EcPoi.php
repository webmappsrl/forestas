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
                fn($translation) => $translation !== null && $translation !== ''
            );
        }

        return parent::getTranslations($key, $allowedLocales);
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
