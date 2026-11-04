<?php

declare(strict_types=1);

namespace App\Nova;

use App\Services\Import\SardegnaSentieriImportService;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Nova\AbstractTaxonomyTermsResource;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\ApiLinksCard;

/**
 * Le avvertenze (oc:8910).
 *
 * Erano una tassonomia a sé, con `taxonomy_warnings` e il suo pivot; ora sono la tassonomia
 * `warnings` del modello unico. La vista resta quella di prima — stesso indirizzo, stessa voce
 * di menu, stesso elenco — e cambia solo la tabella sotto.
 */
class TaxonomyWarning extends AbstractTaxonomyTermsResource
{
    public static function taxonomyIdentifier(): string
    {
        return SardegnaSentieriImportService::WARNINGS_TAXONOMY;
    }

    /** L'indirizzo di prima: i segnalibri di chi la usa continuano a funzionare. */
    public static function uriKey(): string
    {
        return 'taxonomy-warnings';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ...parent::fields($request),
            Text::make('Source ID', 'properties->source_id')->readonly()->hideFromIndex(),
            Text::make(__('Source vocabulary'), 'properties->vocabulary')->readonly()->hideFromIndex(),
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        /** @var TaxonomyTerm $model */
        $model = $request->findModelOrFail();
        $sourceId = data_get($model->properties, 'source_id');

        if (! $sourceId) {
            return [];
        }

        $card = new ApiLinksCard([]);
        $card->addLink('Sardegna Sentieri (termine)', 'https://www.sardegnasentieri.it/taxonomy/term/'.$sourceId.'?_format=json');
        $card->addLink('Sardegna Sentieri (tassonomia)', 'https://www.sardegnasentieri.it/ss/tassonomia/tipologia_di_avvertenze?_format=json');

        return [$card];
    }
}
