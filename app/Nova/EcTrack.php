<?php

declare(strict_types=1);

namespace App\Nova;

use App\Models\EcTrack as EcTrackModel;
use App\Nova\Concerns\HasForestasMediaFields;
use App\Nova\Concerns\HasMirroredRelations;
use App\Nova\Filters\EcTrackCadastralAreaFilter;
use App\Nova\Filters\EcTrackLayerFilter;
use App\Nova\Filters\EcTrackRuoloFilter;
use App\Nova\Filters\EcTrackValidationStatusFilter;
use App\Services\Import\SardegnaSentieriImportService;
use App\Support\InclusivityScore;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Laravel\Nova\Tabs\TabsGroup;
use Marshmallow\Tiptap\Tiptap;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\EcTrackApiLinksCard;
use Wm\WmPackage\Nova\EcTrack as WmNovaEcTrack;
use Wm\WmPackage\Nova\Filters\FeaturesByLayerFilter;
use Wm\WmPackage\Nova\Filters\FeaturesExcludeByIds;
use Wm\WmPackage\Nova\Filters\FeaturesIncludeByIds;
use Wm\WmPackage\Nova\Layer;
use Wm\WmPackage\Support\TaxonomyTermLabel;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Nova\TrailHistoryRenderer;
use Wm\WmPackage\TrailRegistry\Nova\TrailStatusRenderer;
use Wm\WmPackage\TrailRegistry\Services\TrailStatusService;

class EcTrack extends WmNovaEcTrack
{
    use HasForestasMediaFields;
    use HasMirroredRelations;

    public static $model = EcTrackModel::class;

    /**
     * Il tab delle classificazioni che raccoglie gli stati del sentiero (oc:8989): la pratica
     * catastale, la percorribilità che porta il package, e in fondo lo storico.
     * È una chiave, non un'etichetta: Nova la traduce con `__()`, e deve combaciare con il
     * `properties->tab` che `ConfigureTaxonomyTermsCommand` scrive sulla famiglia.
     *
     * Non «Validation»: il filesystem del container non distingue le maiuscole, quindi
     * `__('Validation')` trova `lang/it/validation.php` e restituisce l'array delle regole di
     * validazione invece di una stringa — `Tab::make()` riceve un array e la scheda va in 500.
     */
    public const STATUS_TAB = 'Status';

    public function fields(NovaRequest $request): array
    {
        $parentFields = parent::fields($request);
        $relationTypes = [BelongsToMany::class, MorphToMany::class];
        // I TabsGroup del package si scartano per ricomporli qui sotto; la sezione delle
        // tassonomie resta, perché arriva già nel punto giusto, sopra i Blocchi Dettaglio.
        $regularFields = $this->withoutPackageImageField($this->keepOnlyTaxonomySection($parentFields));
        $regularFields = array_values(array_filter(
            $regularFields,
            fn ($f) => ! in_array(get_class($f), $relationTypes)
        ));
        // Forestas ha una sola app: il filtro App restituirebbe sempre tutte le tracce (oc:8875).
        foreach ($regularFields as $field) {
            if ($field instanceof BelongsTo && $field->attribute === 'app') {
                $field->withoutFilterable();
            }
        }

        // Il pannello delle proprietà (il JSON grezzo) va in fondo alla pagina: è l'ultima cosa
        // che interessa a chi compila una scheda — deciso in call con Alessio il 3/11 (oc:8910).
        // Si estrae qui e si riaggancia dopo i tab, altrimenti finirebbe solo in fondo ai campi
        // semplici e resterebbe comunque sopra le sezioni.
        [$regularFields, $properties] = $this->extract($regularFields, __('Properties'));

        return [
            ...$regularFields,
            Tab::group(__('Details'), [
                Tab::make(__('Forestas'), $this->getForestasTabFields()),
                Tab::make(__('Info'), $this->getInfoTabFields()),
                // Il tab Accessibility del package (14 chiavi ITACA) qui non è valorizzato da
                // nessuna sorgente: serve agli shard che importano da GeoHub (oc:8910).
                Tab::make(__('DEM'), $this->getDemTabFields()),
            ]),
            BelongsToMany::make('EcPois', 'ecPois', EcPoi::class)->searchable()->collapsedByDefault(),
            // SOLA LETTURA, e deve restare cosi', per i punti di interesse come per i sentieri
            // vicini. I punti di interesse correlati sono uno specchio di Drupal e i sentieri
            // vicini sono calcolati dalla geometria: l'import li riscrive per intero a ogni giro
            // (CalculateNearbyTracksJob per i secondi). Un collegamento aggiunto o tolto qui a
            // mano sparirebbe al giro successivo, senza che nessuno capisca perche'. Il blocco di
            // attach e detach sta negli override `authorizedToAttach*` e `authorizedToDetach`
            // di questa classe (oc:8932).
            BelongsToMany::make('Percorsi vicini', 'relatedTracks', self::class)->collapsedByDefault(),
            MorphToMany::make('Layers', 'layers', Layer::class)->collapsedByDefault(),
            // Gli enti collegati al percorso, con il ruolo che hanno su di esso: in Drupal sono
            // la sezione «Contatti a Guide, Gestori ed Enti», e finora si vedevano solo aprendo
            // l'ente. Sola lettura come le altre relazioni importate: gli enti sono uno specchio
            // di Drupal e l'import riscrive la pivot `enteables` (oc:8932).
            MorphToMany::make('Enti', 'enti', Ente::class)
                ->fields(fn () => [
                    Text::make('Ruolo', 'ruolo')->readonly(),
                ])
                ->collapsedByDefault(),
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
     * Lo stato di validazione apre il tab «Validazione» delle classificazioni (oc:8932,
     * oc:8989).
     *
     * Stava nel tab generale finché era l'unico stato del sentiero. Da quando la percorribilità
     * è un dato a sé, i due stati hanno un tab ciascuno: è la stessa separazione che il lavoro
     * ha fatto nei dati, portata nella scheda — lo stato della pratica catastale di qua, lo
     * stato del terreno di là.
     *
     * Non passa da `taxonomyTabExtraFields()`, che appende in coda: è il primo dato che chi
     * compila la scheda cerca, quindi va prima di eventuali campi aggiunti al tab. È la
     * famiglia `validation-status`, il cui campo generico resta tolto da
     * `makeTermFieldsForTaxonomies()`, altrimenti sarebbero due.
     *
     * @return array<string, array<int, Field>>
     */
    public function makeTermFieldsByTab(string $default = 'General'): array
    {
        $byTab = parent::makeTermFieldsByTab($default);
        $byTab[self::STATUS_TAB] = [
            Heading::make('<h3 class="text-base font-bold">'.e(__('Trail validation')).'</h3>')->asHtml(),
            $this->validationStatusDetailBlock(),
            $this->validationStatusField(),
            $this->validationNoteField(),
            ...($byTab[self::STATUS_TAB] ?? []),
        ];

        return $byTab;
    }

    /**
     * Gli attributi dell'avviso: nel package stanno nel tab Info, qui vanno nel tab Stato (oc:8981).
     */
    private const NOTICE_FLAG = 'properties->not_accessible';

    private const NOTICE_MESSAGE = 'properties->not_accessible_message';

    private const NOTICE_DATE = 'properties->'.WmEcTrack::NOT_ACCESSIBLE_UPDATED_AT;

    private const NOTICE_ATTRIBUTES = [self::NOTICE_FLAG, self::NOTICE_MESSAGE, self::NOTICE_DATE];

    /**
     * Fuso e formato con cui l'editor legge la data dell'avviso (salvata in UTC).
     */
    private const NOTICE_TIMEZONE = 'Europe/Rome';

    private const NOTICE_DATE_FORMAT = 'd/m/Y H:i';

    /**
     * Il tab Info del package senza i campi dell'avviso, che si ridisegnano in `noticeFields()`.
     * Si filtra invece di copiare il tab del package, così il resto resta allineato a lui.
     *
     * @return array<int, Field>
     */
    public function getInfoTabFields(): array
    {
        return array_values(array_filter(parent::getInfoTabFields(), function ($field) {
            if ($field instanceof NovaTabTranslatable) {
                foreach ($field->originalFields as $original) {
                    if (in_array($original->attribute, self::NOTICE_ATTRIBUTES, true)) {
                        return false;
                    }
                }

                return true;
            }

            return ! in_array($field->attribute ?? null, self::NOTICE_ATTRIBUTES, true);
        }));
    }

    /**
     * L'avviso del sentiero, nel tab Stato dopo la percorribilità e prima dello storico (oc:8981).
     * Gli attributi sono quelli del package: cambia solo l'etichetta e la posizione.
     *
     * @return array<int, Field>
     */
    private function noticeFields(): array
    {
        return [
            // L'avviso non va nell'index delle tracce: si legge nel detail, nel tab Status.
            Heading::make('<h3 class="text-base font-bold">'.e(__('Notice')).'</h3>')->asHtml()->hideFromIndex(),
            Boolean::make(__('Publish notice'), self::NOTICE_FLAG)->hideFromIndex(),
            NovaTabTranslatable::make([
                Textarea::make(__('Notice message'), self::NOTICE_MESSAGE)
                    ->help(__('Without an Italian message the notice is not shown on the website.')),
            ])->hideFromIndex(),
            Text::make(__('Notice date'), self::NOTICE_DATE)
                ->readonly()
                // Il valore è un ISO 8601 in UTC: l'editor lo legge in ora di Roma.
                ->displayUsing(fn ($value) => $value
                    ? Carbon::parse($value)->setTimezone(self::NOTICE_TIMEZONE)->format(self::NOTICE_DATE_FORMAT)
                    : null)
                ->onlyOnDetail(),
        ];
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
        $fromPackage = parent::taxonomyTabExtraFields();

        return [
            // Il package porta qui il blocco della percorribilità (oc:8989): senza `parent::` i
            // suoi campi sparirebbero, e con loro l'unico modo di cambiare lo stato lasciando
            // una traccia nello storico. Lo storico va dopo, perché questi campi finiscono in
            // coda a quelli di `makeTermFieldsByTab()`: messo lì resterebbe in mezzo.
            self::STATUS_TAB => [
                ...($fromPackage[self::STATUS_TAB] ?? []),
                ...$this->noticeFields(),
                ...$this->historyBlock(),
            ],
            'Inclusivity' => [
                // Punteggi a mano: nessuna sorgente li possiede e l'import non li tocca.
                $this->inclusivityField(__('Physical inclusivity'), 'forestas_inclusivity_physical', 'physical'),
                $this->inclusivityField(__('Aids inclusivity'), 'forestas_inclusivity_aids', 'aids'),
                $this->inclusivityField(__('Digital inclusivity'), 'forestas_inclusivity_digital', 'digital'),
                Text::make(__('Independent use'), 'properties->forestas->inclusivity_independent_use')->rules('nullable', 'max:30')->hideFromIndex(),
                Text::make(__('Assistance availability'), 'properties->forestas->inclusivity_assistance')->rules('nullable', 'max:30')->hideFromIndex(),
                // Derivati, non scritti da nessuno: si calcolano dai tre valori sopra.
                Text::make(__('Inclusivity score'), fn () => $this->inclusivity()['score'] ?? null)->onlyOnDetail(),
                Text::make(__('Inclusivity class'), fn () => $this->inclusivity()['class'] ?? null)->onlyOnDetail(),
            ],
        ];
    }

    /**
     * Uno dei tre valori dell'inclusività, con il suo intervallo da `config/forestas.php`.
     *
     * L'intervallo compare in tre posti — l'etichetta, gli attributi del campo e la regola di
     * validazione — e vengono tutti e tre da lì: `min()` e `max()` di Nova scrivono solo i
     * `meta` del campo, cioè gli attributi dell'`<input>` nel browser, e non aggiungono nessuna
     * regola. Senza `rules()` una richiesta fuori dall'interfaccia scrive quello che vuole, la
     * colonna è `smallInteger` e accetta fino a 32767, e il punteggio finisce fuori scala:
     * `InclusivityScore::classFor()` non trova nessuna classe e il sentiero sparisce dai filtri
     * senza che niente segnali un errore (oc:8910).
     */
    private function inclusivityField(string $label, string $attribute, string $range): Number
    {
        $min = (int) config("forestas.inclusivity_ranges.{$range}.min");
        $max = (int) config("forestas.inclusivity_ranges.{$range}.max");

        return Number::make("{$label} ({$min}-{$max})", $attribute)
            ->min($min)
            ->max($max)
            ->rules('nullable', 'integer', "between:{$min},{$max}")
            ->nullable()
            ->hideFromIndex();
    }

    /**
     * Il punteggio del sentiero in scheda, o `null` su una risorsa vuota (Nova costruisce i
     * campi anche su un'istanza senza modello, in creazione).
     *
     * @return array{score: int, class: string}|null
     */
    private function inclusivity(): ?array
    {
        return $this->resource instanceof EcTrackModel
            ? InclusivityScore::for($this->resource)
            : null;
    }

    /**
     * Lo stato di validazione, in cima alla scheda e in lista come quando era una colonna
     * (oc:8932). È la famiglia `validation-status` del modello unico, a scelta singola: il campo
     * generico del package la mostrerebbe nel tab delle classificazioni, senza colonna in lista,
     * quindi si toglie da lì (`makeTermFieldsForTaxonomies`) e si disegna qui.
     */
    private function validationStatusField(): Select
    {
        $terms = EcTrackValidationStatusFilter::statuses();
        $options = $terms->mapWithKeys(fn ($term) => [$term->identifier => TaxonomyTermLabel::for($term)])->all();

        // «Stato», sotto il titolo «Validazione»: il titolo dice già di quale stato si parla.
        // Il nome vale anche per la colonna in lista, dove il campo compare senza quel titolo
        // accanto: lì «Stato» è più corto di «Stato validazione» e la colonna resta la stessa.
        return Select::make(__('Status'), 'validation_status')
            ->options($options)
            ->displayUsingLabels()
            ->nullable()
            // In detail lo mostra `validationStatusDetailBlock()`, con autore e data: il campo
            // ripeterebbe il solo valore sotto lo stesso titolo.
            ->hideFromDetail()
            ->rules('nullable', Rule::in(array_keys($options)))
            ->resolveUsing(fn ($value, $resource) => $resource->exists
                ? $resource->term(EcTrackModel::VALIDATION_STATUS_TAXONOMY)?->identifier
                : null)
            ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) use ($terms) {
                $wanted = $request->input($requestAttribute);
                $wanted = $wanted === null || $wanted === '' ? null : $wanted;

                if ($wanted !== null && $terms->firstWhere('identifier', $wanted) === null) {
                    return;
                }

                // Svuotare il campo toglie lo stato: il service sa portare da uno stato a un
                // altro, non a nessuno — un evento senza termine di arrivo non esiste.
                if ($wanted === null) {
                    return function () use ($model, $terms) {
                        /** @var EcTrackModel $model */
                        $model->taxonomyTerms()->detach($terms->pluck('id')->all());
                        $model->unsetRelation('taxonomyTerms');
                    };
                }

                /** @var string|null $note */
                $note = $request->input('validation_note');
                $note = is_string($note) && trim($note) !== '' ? $note : null;

                $userId = $request->user()?->getKey() ?? auth()->id();

                // Dopo il salvataggio: su una traccia nuova l'id non esiste ancora. Passa dal
                // service perché il cambio finisca nello storico con autore e data, come la
                // percorribilità: scrivere il pivot a mano lo lascerebbe fuori (oc:8989).
                return function () use ($model, $wanted, $note, $userId) {
                    /** @var EcTrackModel $model */
                    app(TrailStatusService::class)->set(
                        $model,
                        EcTrackModel::VALIDATION_STATUS_TAXONOMY,
                        $wanted,
                        $note,
                        $userId === null ? null : (int) $userId,
                        TrailStatusEventSource::Manual,
                    );
                };
            });
    }

    /**
     * La nota del passaggio di validazione. Non ha un valore proprio da salvare: la legge il
     * `fillUsing()` dello stato, che è l'unico a scrivere. Senza `fillUsing()` vuoto Nova
     * cercherebbe una colonna `validation_note` sul modello.
     */
    private function validationNoteField(): Textarea
    {
        return Textarea::make(__('Note'), 'validation_note')
            ->nullable()
            ->onlyOnForms()
            ->resolveUsing(fn () => null)
            ->fillUsing(fn () => null)
            ->help(__('Why the status changed: it stays in the history, with your name and the date.'));
    }

    /**
     * Toglie lo stato di validazione dai campi generici delle tassonomie: ha il suo campo,
     * `validationStatusField()` (oc:8932).
     */
    public function makeTermFieldsForTaxonomies(array $taxonomyIdentifiers): array
    {
        return parent::makeTermFieldsForTaxonomies(
            array_values(array_diff($taxonomyIdentifiers, [EcTrackModel::VALIDATION_STATUS_TAXONOMY]))
        );
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
            new EcTrackValidationStatusFilter,
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        /** @var EcTrackModel $track */
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
            ])->hideFromIndex(),
            ...$this->forestasMediaFields(),
        ];
    }

    /**
     * Lo stato della pratica catastale in detail: valore, autore e data, come la percorribilità
     * (oc:8989).
     *
     * Autore e data compaiono quando esiste un evento di quella famiglia in `trail_status_events`.
     * Oggi non ne esistono: nessun percorso li registra ancora per la validazione, e il blocco
     * mostra il solo valore. Quando la validazione datata arriverà, si riempirà da sé senza
     * toccare questo codice.
     */
    private function validationStatusDetailBlock(): Text
    {
        // Nome vuoto: il titolo è l'intestazione che precede il blocco, e ripeterlo nella
        // colonna di sinistra è la stessa parola due volte sulla stessa riga.
        return Text::make(' ', fn () => TrailStatusRenderer::render(
            $this->resource,
            EcTrackModel::VALIDATION_STATUS_TAXONOMY,
            __('Not recorded'),
        ))->asHtml()->onlyOnDetail();
    }

    /**
     * Lo storico, in fondo al tab «Stato» (oc:8989).
     *
     * Sta qui e non in una sezione a parte: chi registra un dato vuole vedere lì sotto cosa è
     * stato registrato prima, non in un'altra parte della pagina. È uno solo per tutti gli stati,
     * perché la tabella degli eventi è una: `TrailHistoryRenderer` la legge senza filtrare la
     * famiglia, unendoci i passaggi dei codici catastali.
     *
     * @return array<int, Field>
     */
    private function historyBlock(): array
    {
        if (! config('wm-package.features.trail_registry.enabled') || ! $this->resource->exists) {
            return [];
        }

        return [
            Heading::make('<h3 class="text-base font-bold">'.e(__('History')).'</h3>')->asHtml()->onlyOnDetail(),
            Text::make(
                ' ',
                fn () => TrailHistoryRenderer::render($this->resource),
            )->asHtml()->onlyOnDetail(),
        ];
    }
}
