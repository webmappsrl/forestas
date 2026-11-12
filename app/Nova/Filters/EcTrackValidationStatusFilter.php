<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use App\Models\EcTrack;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Nova\Filters\Filter;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Support\TaxonomyTermLabel;

/**
 * Tracce con un certo stato di validazione (oc:8932): il termine scelto nella famiglia
 * `validation-status`. Le tracce senza stato non hanno un valore che le trovi.
 */
class EcTrackValidationStatusFilter extends Filter
{
    public function name(): string
    {
        return __('Validation status');
    }

    public function apply(Request $request, $query, $value): Builder
    {
        return $query->whereHas('taxonomyTerms', fn ($terms) => $terms->where('taxonomy_terms.identifier', $value));
    }

    public function options(Request $request): array
    {
        return self::statuses()
            ->mapWithKeys(fn (TaxonomyTerm $term) => [TaxonomyTermLabel::for($term) => $term->identifier])
            ->all();
    }

    /**
     * I termini della famiglia, nell'ordine dei dati; vuoto se la famiglia non esiste ancora.
     *
     * @return Collection<int, TaxonomyTerm>
     */
    public static function statuses(): Collection
    {
        $family = TaxonomyTerm::query()->taxonomies()
            ->where('identifier', EcTrack::VALIDATION_STATUS_TAXONOMY)
            ->first();

        return $family === null ? collect() : $family->children()->get();
    }
}
