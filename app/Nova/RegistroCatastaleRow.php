<?php

namespace App\Nova;

use App\Models\RegistroCatastaleRow as RegistroCatastaleRowModel;
use App\Nova\Filters\RegistroRowColumnFilter;
use App\Nova\Filters\RegistroRowLinkedFilter;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Query\Search\Column;
use Wm\WmPackage\TrailRegistry\Nova\HidesWhenTrailRegistryDisabled;

/**
 * L'elenco delle righe del registro catastale (oc:8700): prende il posto del
 * foglio Google quando smettera' di essere aggiornato. Tutte le righe, anche
 * quelle senza codice, in sola lettura: il mirror lo scrive solo l'import, e
 * gli id cambiano a ogni giro.
 *
 * Chi la vede lo decide RegistroCatastaleRowPolicy, con la regola del Catasto
 * del package; il trait la nasconde a dominio spento.
 *
 * @mixin RegistroCatastaleRowModel
 *
 * @property RegistroCatastaleRowModel $resource
 */
class RegistroCatastaleRow extends Resource
{
    use HidesWhenTrailRegistryDisabled;

    public static $model = RegistroCatastaleRowModel::class;

    public static $globallySearchable = false;

    /** Eager load del codice: il BelongsTo dell'index non faccia una query per riga. */
    public static $with = ['trailRegistryCode'];

    /**
     * Le righe con solo il link non hanno numero: senza ripiego il titolo
     * (breadcrumb, relazioni) resterebbe vuoto.
     */
    public function title(): string
    {
        $numero = $this->resource->sheet_number;

        if ($numero !== null && $numero !== '') {
            return (string) $numero;
        }

        return __(':sheet · row :row', [
            'sheet' => (string) $this->resource->sheet_name,
            'row' => (string) $this->resource->row_number,
        ]);
    }

    public static function uriKey(): string
    {
        return 'registro-catastale-rows';
    }

    public static function label(): string
    {
        return __('Registry rows');
    }

    public static function singularLabel(): string
    {
        return __('Registry row');
    }

    /**
     * Il numero non si cerca su `number`: e' il numero a due cifre del
     * catasto (`101` e' settore 1, numero 1), e su PostgreSQL Nova userebbe
     * `ilike` su una colonna intera, che va in errore. Si cerca sul numero
     * ricomposto come sul foglio. lpad tronca oltre due cifre: va bene perche'
     * il numero del catasto ha sempre due cifre (0-99, lo garantisce il parser).
     */
    public static function searchableColumns(): array
    {
        return [Column::raw("concat(sector, lpad(number::text, 2, '0'), nullif(variant, '0'))")];
    }

    /**
     * Per nome della tab, poi per riga del foglio (oggi l'ordine alfabetico
     * delle tab coincide con quello del file). Un orderBy in indexQuery()
     * arriverebbe dopo il latest(id) di Nova e non conterebbe.
     */
    public static function defaultOrderings(Builder $query)
    {
        return $query->orderBy('sheet_name')->orderBy('row_number');
    }

    public function fields(NovaRequest $request): array
    {
        return [
            // Primo campo: in testa all'index e sopra la tabella nel detail.
            BelongsTo::make(__('Code'), 'trailRegistryCode', TrailRegistryCode::class)->exceptOnForms(),
            Text::make(__('Tab'), 'sheet_name')->onlyOnIndex(),
            Text::make(__('Area'), 'area')->onlyOnIndex(),
            Text::make(__('Sector'), 'sector')->onlyOnIndex(),
            Text::make(__('Number'), 'sheet_number')->onlyOnIndex(),
            Text::make(__('Registry'), fn () => RegistroTabRenderer::renderRowDetail($this->resource))
                ->asHtml()->onlyOnDetail(),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new RegistroRowColumnFilter('sheet_name', 'Tab'),
            new RegistroRowColumnFilter('area', 'Area'),
            new RegistroRowColumnFilter('sector', 'Sector'),
            new RegistroRowLinkedFilter,
        ];
    }
}
