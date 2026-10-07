<?php

namespace App\Nova;

use App\Nova\Filters\TrailAnomalySourceFilter;
use App\Services\RegistroCatastale\RegistroAnomalyTypes;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly as WmTrailRegistryAnomaly;

// Aggiunge la seconda provenienza «registro» (oc:8539) sopra quella del
// Catasto gia' gestita dalla classe base: titolo, campo soggetto, testo
// della notice e filtro per provenienza. La uriKey resta quella fissa
// della classe base, da cui il package continua a cercare questa Resource.
class TrailRegistryAnomaly extends WmTrailRegistryAnomaly
{
    /**
     * Il titolo delle anomalie del registro non ha una traccia da nominare
     * (spesso `ec_track_id` e' nullo, o e' condiviso con un'altra anomalia):
     * si usa foglio e riga, gli unici dati che identificano la riga sul
     * foglio Google da cui l'anomalia e' nata. Le anomalie del Catasto
     * restano quelle del package.
     */
    protected function titleFor(\Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly $anomaly): string
    {
        if ($anomaly->source === RegistroAnomalyTypes::SOURCE) {
            $context = $anomaly->context ?? [];

            return __(':sheet · row :row', [
                'sheet' => (string) ($context['sheet'] ?? ''),
                'row' => (string) ($context['row'] ?? ''),
            ]);
        }

        return parent::titleFor($anomaly);
    }

    /**
     * Un'anomalia del registro senza traccia (es. link orfano) non ha nulla
     * da linkare verso il Catasto: si mostra il link letto dal foglio, unico
     * appiglio verso la piattaforma di origine.
     */
    protected function subjectField(): Field
    {
        if ($this->resource->source === RegistroAnomalyTypes::SOURCE && $this->resource->ec_track_id === null) {
            return Text::make(
                __('Trail'),
                fn () => e($this->context['link'] ?? ''),
            )->asHtml();
        }

        return parent::subjectField();
    }

    /**
     * La spiegazione in cima all'elenco: quella del package parla solo del
     * Catasto, qui si aggiunge la seconda provenienza (oc:8539).
     */
    protected function noticeBody(): string
    {
        $registro = '<h3 class="text-lg font-bold mb-3 mt-6">'.e(__('Anomalies from the registry')).'</h3>'
            .'<p class="mb-3">'.e(__('These are rows from the registro catastale Google Sheet that did not cleanly attach to a trail registry code: a number different from the sheet, a link that leads to no track, a track without an active code, a number the fallback cannot attribute to a single code, several sheet rows for the same code, or length or time values that cannot be interpreted. These are also corrected at the source — the sheet or the trail record — not here: the next import run makes them disappear on their own once the data is consistent again.')).'</p>';

        return parent::noticeBody().$registro;
    }

    public function filters(NovaRequest $request): array
    {
        return [
            ...parent::filters($request),
            new TrailAnomalySourceFilter,
        ];
    }
}
