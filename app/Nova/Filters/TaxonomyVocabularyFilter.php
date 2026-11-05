<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class TaxonomyVocabularyFilter extends Filter
{
    /** @param class-string<Model> $modelClass */
    public function __construct(private readonly string $modelClass) {}

    /**
     * «Vocabolario di origine» e non «Vocabolario»: nomina il vocabolario di Drupal da cui il
     * termine è stato importato (`properties->vocabulary`), non una tassonomia nostra. Con la
     * sola parola «Vocabolario» si confonderebbe con le tassonomie del modello unico (oc:8910).
     */
    public function name(): string
    {
        return __('Source vocabulary');
    }

    public function apply(Request $request, $query, $value): Builder
    {
        return $query->whereRaw("properties->>'vocabulary' = ?", [$value]);
    }

    public function options(Request $request): array
    {
        return $this->modelClass::query()
            ->get()
            ->pluck('properties.vocabulary')
            ->filter()
            ->unique()
            ->sort()
            ->mapWithKeys(fn ($v) => [$v => $v])
            ->toArray();
    }
}
