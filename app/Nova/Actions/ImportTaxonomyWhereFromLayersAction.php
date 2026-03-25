<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Services\Import\TaxonomyWhereLayersImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;

class ImportTaxonomyWhereFromLayersAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $standalone = true;

    public function name(): string
    {
        return __('Import TaxonomyWhere (Aree Catastali)');
    }

    public function handle(ActionFields $fields, \Illuminate\Support\Collection $models): mixed
    {
        try {
            $result = app(TaxonomyWhereLayersImportService::class)->import(
                $fields->get('app_id') !== null ? (int) $fields->get('app_id') : null
            );
        } catch (\Throwable $e) {
            return Action::danger('Import fallito: '.$e->getMessage());
        }

        return Action::message(
            "Import completato. Creati: {$result['created']}, aggiornati: {$result['updated']}, ".
            "saltati(layer_id mancante): {$result['skipped_missing_layer_id']}, ".
            "saltati(match mancante): {$result['skipped_missing_match']}, ".
            "saltati(geometria non valida): {$result['skipped_invalid_geometry']}, ".
            "errori: {$result['errors']}, ".
            "tracks sincronizzate: {$result['synced_tracks']}."
        );
    }

    public function fields(NovaRequest $request): array
    {
        $apps = App::all();

        if ($apps->count() <= 1) {
            return [];
        }

        return [
            Select::make('App', 'app_id')
                ->options($apps->pluck('name', 'id')->toArray())
                ->rules('required'),
        ];
    }
}
