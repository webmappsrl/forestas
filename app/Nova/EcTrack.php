<?php

declare(strict_types=1);

namespace App\Nova;

use App\Enums\StatoValidazione;
use App\Nova\Filters\EcTrackCadastralAreaFilter;
use App\Nova\Filters\EcTrackLayerFilter;
use App\Nova\Filters\EcTrackRuoloFilter;
use App\Services\Import\SardegnaSentieriImportService;
use App\Support\InclusivityScore;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Laravel\Nova\Tabs\TabsGroup;
use Marshmallow\Tiptap\Tiptap;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\EcTrackApiLinksCard;
use Wm\WmPackage\Nova\EcTrack as WmNovaEcTrack;
use Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter;
use Wm\WmPackage\Nova\Filters\FeaturesExcludeByIds;
use Wm\WmPackage\Nova\Filters\FeaturesIncludeByIds;
use Wm\WmPackage\Nova\Layer;

class EcTrack extends WmNovaEcTrack
{
    public static $model = \App\Models\EcTrack::class;

    public function fields(NovaRequest $request): array
    {
        $parentFields = parent::fields($request);
        $relationTypes = [BelongsToMany::class, MorphToMany::class];
        $regularFields = array_values(array_filter($parentFields, fn ($f) => ! ($f instanceof TabsGroup) && ! in_array(get_class($f), $relationTypes)));
        // Forestas ha una sola app: il filtro App restituirebbe sempre tutte le tracce (oc:8875).
        foreach ($regularFields as $field) {
            if ($field instanceof BelongsTo && $field->attribute === 'app') {
                $field->withoutFilterable();
            }
        }

        // La sezione delle classificazioni va sopra il pannello «Blocchi Dettaglio», che arriva
        // dalla Resource del package ed è in mezzo ai campi ereditati: va inserita lì dentro,
        // non in coda, altrimenti finisce sotto (oc:8910).
        $regularFields = $this->insertBefore(
            $regularFields,
            __('Detail Blocks'),
            $this->makeTaxonomySection()
        );

        // Il pannello delle proprietà (il JSON grezzo) va in fondo alla pagina: è l'ultima cosa
        // che interessa a chi compila una scheda — deciso in call con Alessio il 3/11 (oc:8910).
        // Si estrae qui e si riaggancia dopo i tab, altrimenti finirebbe solo in fondo ai campi
        // semplici e resterebbe comunque sopra le sezioni.
        [$regularFields, $properties] = $this->extract($regularFields, __('Properties'));

        return [
            ...$regularFields,
            Select::make(__('Validation status'), 'stato_validazione')
                ->options(
                    collect(StatoValidazione::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                        ->toArray()
                )
                ->nullable()
                ->filterable(),
            Tab::group(__('Details'), [
                Tab::make(__('Forestas'), $this->getForestasTabFields()),
                Tab::make(__('Info'), $this->getInfoTabFields()),
                // Il tab Accessibility del package (14 chiavi ITACA) qui non è valorizzato da
                // nessuna sorgente: serve agli shard che importano da GeoHub (oc:8910).
                Tab::make(__('DEM'), $this->getDemTabFields()),
            ]),
            BelongsToMany::make('EcPois', 'ecPois', EcPoi::class)->searchable()->collapsedByDefault(),
            MorphToMany::make('Layers', 'layers', Layer::class)->collapsedByDefault(),
            ...$properties,
        ];
    }

    /**
     * Toglie dall'elenco i campi con il nome dato e li restituisce a parte.
     *
     * @param  array<int, mixed>  $fields
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}
     */
    private function extract(array $fields, string $name): array
    {
        $matches = fn ($f) => ((string) ($f->name ?? '')) === $name;

        return [
            array_values(array_filter($fields, fn ($f) => ! $matches($f))),
            array_values(array_filter($fields, $matches)),
        ];
    }

    /**
     * Inserisce un campo prima di quello con il nome dato; in coda se quel nome non c'è.
     *
     * @param  array<int, mixed>  $fields
     * @return array<int, mixed>
     */
    private function insertBefore(array $fields, string $name, mixed $nuovo): array
    {
        foreach ($fields as $i => $field) {
            if (((string) ($field->name ?? '')) === $name) {
                array_splice($fields, $i, 0, [$nuovo]);

                return $fields;
            }
        }

        return [...$fields, $nuovo];
    }

    /**
     * Le tassonomie storiche, senza le righe che attività non sono (oc:8910).
     *
     * `sardegnasentieri:type:*` e `sardegnasentieri:cammino` dicono che tipo di percorso è, non
     * cosa ci si fa: la stessa informazione sta nella tassonomia «Tipo percorso», e Alessio in
     * call il 3/11 ha confermato che sono due cose ortogonali.
     *
     * Si nascondono e non si cancellano: i layer «Sentieri», «Itinerari» e «Cammini di Sardegna»
     * associano le tracce proprio con quelle attività (`Layer`, fallback su
     * `whereHas('taxonomyActivities')`), e toglierle dal database li spegnerebbe. La
     * cancellazione vera richiede prima di spostare quei layer su un altro criterio.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function legacyTaxonomies(): array
    {
        return array_map(function (array $taxonomy) {
            if (($taxonomy['key'] ?? null) !== 'activities') {
                return $taxonomy;
            }

            $taxonomy['hidden'] = [
                SardegnaSentieriImportService::TYPE_ACTIVITY_PREFIX.'sentiero',
                SardegnaSentieriImportService::TYPE_ACTIVITY_PREFIX.'itinerario',
                SardegnaSentieriImportService::CAMMINO_ACTIVITY_IDENTIFIER,
            ];

            return $taxonomy;
        }, parent::legacyTaxonomies());
    }

    /**
     * I campi dell'inclusività che non sono tassonomie: li possiede solo Forestas (oc:8910).
     *
     * La chiave è il tab dichiarato dai due tassonomie dell'inclusività: i campi gli finiscono
     * accanto, nella sezione che costruisce il package.
     *
     * @return array<string, array<int, Field>>
     */
    protected function taxonomyTabExtraFields(): array
    {
        return [
            'Inclusivity' => [
                // Punteggi a mano: nessuna sorgente li possiede e l'import non li tocca.
                Number::make(__('Physical inclusivity (11-55)'), 'forestas_inclusivity_physical')->min(11)->max(55)->nullable()->hideFromIndex(),
                Number::make(__('Aids inclusivity (1-23)'), 'forestas_inclusivity_aids')->min(1)->max(23)->nullable()->hideFromIndex(),
                Number::make(__('Digital inclusivity (0-22)'), 'forestas_inclusivity_digital')->min(0)->max(22)->nullable()->hideFromIndex(),
                Text::make(__('Independent use'), 'properties->forestas->inclusivity_independent_use')->rules('nullable', 'max:30')->hideFromIndex(),
                Text::make(__('Assistance availability'), 'properties->forestas->inclusivity_assistance')->rules('nullable', 'max:30')->hideFromIndex(),
                // Derivati, non scritti da nessuno: si calcolano dai tre valori sopra.
                Text::make(__('Inclusivity score'), fn () => InclusivityScore::for($this->resource instanceof \App\Models\EcTrack ? $this->resource : new \App\Models\EcTrack)['score'] ?? null)->onlyOnDetail(),
                Text::make(__('Inclusivity class'), fn () => InclusivityScore::for($this->resource instanceof \App\Models\EcTrack ? $this->resource : new \App\Models\EcTrack)['class'] ?? null)->onlyOnDetail(),
            ],
        ];
    }

    public function filters(NovaRequest $request): array
    {
        // Il filtro dei layer del package va sostituito, non affiancato: con due filtri
        // sulla stessa chiave Nova applicherebbe il primo che trova (oc:8806).
        // Include/Exclude per id servivano al vecchio campo dei layer, che oggi usa rotte sue:
        // nel menu comparivano senza controllo, inutilizzabili (oc:8875).
        $filters = [];
        foreach (parent::filters($request) as $filter) {
            if (get_class($filter) === FeaturesByLayerFilter::class) {
                $filters[] = new EcTrackLayerFilter($this->resource->getLayerRelationName());
                $filters[] = new EcTrackCadastralAreaFilter;

                continue;
            }
            if ($filter instanceof FeaturesIncludeByIds || $filter instanceof FeaturesExcludeByIds) {
                continue;
            }
            $filters[] = $filter;
        }

        return [
            ...$filters,
            new EcTrackRuoloFilter,
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        /** @var \App\Models\EcTrack $track */
        $track = $request->findModelOrFail();
        $card = new EcTrackApiLinksCard($track);

        $sourceId = data_get($track->properties, 'forestas.source_id');
        if ($sourceId) {
            $card->addLink('Sardegna Sentieri', 'https://www.sardegnasentieri.it/ss/track/'.$sourceId.'?_format=json');
        }

        return [$card];
    }

    public function getForestasTabFields(): array
    {
        return [
            Text::make('Codice', 'forestas_registry_code')->readonly(),
            Date::make('Data di rilievo', 'forestas_survey_date')->readonly(),
            Text::make('Sardegna Sentieri ID', 'properties->sardegnasentieri_id')->readonly(),
            Text::make('Source ID', 'properties->forestas->source_id')->readonly(),
            Text::make('URL', 'properties->forestas->url')->readonly(),
            Text::make('Aggiornato il', 'properties->forestas->updated_at')->readonly(),
            Text::make('Creato il', 'properties->forestas->created_at')->readonly(),
            NovaTabTranslatable::make([
                Tiptap::make('Info utili', 'properties->forestas->info_utili')->readonly(),
                Tiptap::make('Roadbook', 'properties->forestas->roadbook')->readonly(),
            ])->hideFromIndex(),
            KeyValue::make('Allegati', 'properties->forestas->allegati'),
            KeyValue::make('Video', 'properties->forestas->video'),
            KeyValue::make('GPX', 'properties->forestas->gpx'),
            KeyValue::make('Zona geografica', 'properties->forestas->zona_geografica'),
        ];
    }
}
