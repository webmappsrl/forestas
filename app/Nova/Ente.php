<?php

declare(strict_types=1);

namespace App\Nova;

use App\Enums\TipoEnte;
use App\Models\Ente as EnteModel;
use App\Nova\Concerns\HasForestasMediaFields;
use App\Rules\UniqueEnteSlug;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Marshmallow\Tiptap\Tiptap;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\ApiLinksCard;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\Enums\GeometryType;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;

class Ente extends Resource
{
    /**
     * Pulsanti del Tiptap di descrizione e contatti: gli stessi tag della whitelist di
     * EnteHtmlSanitizer (oc:8965), così un salvataggio da Nova non toglie la formattazione.
     */
    private const RICH_TEXT_BUTTONS = ['heading', 'bold', 'italic', 'link', 'bulletList', 'orderedList'];

    private const RICH_TEXT_HEADING_LEVELS = [2, 3, 4, 5, 6];

    use HasForestasMediaFields;

    public static $model = EnteModel::class;

    public static $title = 'name';

    /** @var list<string> */
    public static $search = ['name', 'sardegnasentieri_id'];

    /**
     * Ricerca di Nova sul database (oc:8965). Il modello usa Scout per la ricerca del sito, e
     * Nova lo attiverebbe da sé: `$search` verrebbe ignorato, gli enti spariti da Drupal non si
     * troverebbero più e la ricerca andrebbe in errore senza indice. Come AbstractEcResource.
     */
    public static function usesScout(): bool
    {
        return false;
    }

    public static function label(): string
    {
        return 'Enti';
    }

    public static function singularLabel(): string
    {
        return 'Ente';
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        /** @var EnteModel $ente */
        $ente = $request->findModelOrFail();
        $card = new ApiLinksCard([]);
        $card->addLink('Sardegna Sentieri (JSON)', 'https://www.sardegnasentieri.it/node/'.$ente->sardegnasentieri_id.'?_format=json');
        $card->addLink('Sardegna Sentieri (HTML)', 'https://www.sardegnasentieri.it/node/'.$ente->sardegnasentieri_id);

        return [$card];
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Nome', 'name'),
            Text::make('Sardegna Sentieri ID', 'sardegnasentieri_id')->readonly(),
            NovaTabTranslatable::make([
                Tiptap::make('Contatti', 'contatti')
                    ->buttons(self::RICH_TEXT_BUTTONS)
                    ->headingLevels(self::RICH_TEXT_HEADING_LEVELS),
            ])->hideFromIndex(),
            Text::make('Pagina Web', 'pagina_web'),
            Text::make('Titolo pagina web', 'pagina_web_title')->hideFromIndex(),
            // Lo slug è l'URL della pagina dell'ente sul sito WordPress (oc:8965): unico e solo
            // ASCII, perché il sito costruisce gli URL con sanitize_title(), che toglie gli accenti.
            Text::make('Slug', 'slug')
                ->hideFromIndex()
                // Unico sul valore che si salva, cioè già normalizzato: «Comune di Lodè» va
                // confrontato come `comune-di-lode`.
                ->rules('nullable', 'max:255', new UniqueEnteSlug($request->resourceId))
                ->fillUsing(function (NovaRequest $request, $model, string $attribute, string $requestAttribute) {
                    $name = $model instanceof EnteModel ? (string) $model->getTranslation('name', 'it', false) : '';
                    $model->{$attribute} = EnteModel::normalizeSlug((string) $request->input($requestAttribute), $name);
                }),
            DateTime::make('Creato in Drupal', 'drupal_created_at')->readonly()->hideFromIndex(),
            // Tabella chiave-valore come su percorsi e punti di interesse: stesso dato
            // (`properties.related_url`), stessa forma. In sola lettura perche' l'import lo
            // riscrive per intero a ogni giro e toglie la chiave se in Drupal non ci sono piu'
            // collegamenti (`SardegnaSentieriImportService::applyRelatedUrl()`) (oc:8932).
            KeyValue::make('Collegamenti', 'properties->related_url')->readonly()->hideFromIndex(),
            Select::make('Tipo Ente', 'tipo_ente')
                ->options(collect(TipoEnte::cases())->mapWithKeys(
                    fn (TipoEnte $case) => [$case->value => $case->label()]
                )->toArray())
                ->displayUsingLabels()
                ->nullable(),
            ...$this->forestasMediaFields(),
            NovaTabTranslatable::make([
                Tiptap::make('Descrizione', 'description')
                    ->buttons(self::RICH_TEXT_BUTTONS)
                    ->headingLevels(self::RICH_TEXT_HEADING_LEVELS),
            ])->hideFromIndex(),
            FeatureCollectionMap::make('Geometry', 'geometry')
                ->forGeometryTypes(GeometryType::Point)
                ->hideFromIndex()
                ->nullable(),
            MorphToMany::make('Track gestiti', 'ecTracksGestore', EcTrack::class)
                ->display('name')->hideFromIndex(),
            MorphToMany::make('Track manutentore', 'ecTracksManutentore', EcTrack::class)
                ->display('name')->hideFromIndex(),
            MorphToMany::make('Track rilevatore', 'ecTracksRilevatore', EcTrack::class)
                ->display('name')->hideFromIndex(),
            MorphToMany::make('Track operatore', 'ecTracksOperatore', EcTrack::class)
                ->display('name')->hideFromIndex(),
            MorphToMany::make('Track complesso forestale', 'ecTracksComplessoForestale', EcTrack::class)
                ->display('name')->hideFromIndex(),
        ];
    }
}
