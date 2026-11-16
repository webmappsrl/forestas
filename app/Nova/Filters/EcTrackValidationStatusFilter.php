<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use App\Console\Commands\ConfigureTaxonomyTermsCommand;
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

        if ($family === null) {
            return collect();
        }

        // Solo gli stati che il comando dei termini dichiara: un termine rimasto in tabella da
        // una configurazione precedente — i due di terreno usciti da questa famiglia (oc:8989) —
        // comparirebbe fra le scelte, e chi lo sceglie rimette un sentiero in uno stato che non
        // esiste più.
        $declared = ConfigureTaxonomyTermsCommand::termIdentifiersOf(EcTrack::VALIDATION_STATUS_TAXONOMY);

        return $family->children()->get()
            ->filter(fn (TaxonomyTerm $term) => in_array($term->identifier, $declared, true))
            ->values();
    }
}
