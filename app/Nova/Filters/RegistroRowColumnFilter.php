<?php

namespace App\Nova\Filters;

use App\Models\RegistroCatastaleRow;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Filtra le righe del registro su una colonna del mirror (oc:8700): Tab
 * (`sheet_name`), Area, Settore. Le opzioni sono i valori presenti, non un
 * elenco fisso: le tab e le aree sono del foglio, non del codice.
 *
 * Tab e Area oggi danno le stesse righe (ogni tab e' un'area): l'Area resta
 * perche' una tab nuova che non corrisponde a un'area si noterebbe.
 */
class RegistroRowColumnFilter extends Filter
{
    public $component = 'select-filter';

    public function __construct(private string $column, private string $label) {}

    public function key(): string
    {
        return 'registro-row-'.$this->column;
    }

    public function name(): string
    {
        return __($this->label);
    }

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where($this->column, $value);
    }

    public function options(NovaRequest $request): array
    {
        return RegistroCatastaleRow::query()
            ->whereNotNull($this->column)
            ->distinct()
            ->orderBy($this->column)
            ->pluck($this->column)
            ->mapWithKeys(fn ($value) => [$value => $value])
            ->all();
    }
}
