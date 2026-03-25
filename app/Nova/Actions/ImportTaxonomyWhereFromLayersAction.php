<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Services\Import\TaxonomyWhereLayersImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

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
            $result = app(TaxonomyWhereLayersImportService::class)->import();
        } catch (\Throwable $e) {
            return Action::danger('Import fallito: '.$e->getMessage());
        }

        return Action::message(
            "Import completato. Creati: {$result['created']}, aggiornati: {$result['updated']}, ".
            "saltati(layer_id mancante): {$result['skipped_missing_layer_id']}, ".
            "saltati(match mancante): {$result['skipped_missing_match']}, ".
            "saltati(geometria non valida): {$result['skipped_invalid_geometry']}, ".
            "errori: {$result['errors']}."
        );
    }

    public function fields(\Laravel\Nova\Http\Requests\NovaRequest $request): array
    {
        return [];
    }
}
