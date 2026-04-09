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

    public function name(): string
    {
        return __('Vocabulary');
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
