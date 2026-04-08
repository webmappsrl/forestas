<?php

declare(strict_types=1);

namespace App\Nova;

use App\Enums\TipoEnte;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\ID;
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
    public static $model = \App\Models\Ente::class;

    public static $title = 'name';

    /** @var list<string> */
    public static $search = ['name', 'sardegnasentieri_id'];

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
                Tiptap::make('Contatti', 'contatti'),
            ])->hideFromIndex(),
            Text::make('Pagina Web', 'pagina_web'),
            Select::make('Tipo Ente', 'tipo_ente')
                ->options(collect(TipoEnte::cases())->mapWithKeys(
                    fn (TipoEnte $case) => [$case->value => $case->label()]
                )->toArray())
                ->displayUsingLabels()
                ->nullable(),
            Images::make('Immagine', 'default')->hideFromIndex(),
            NovaTabTranslatable::make([
                Tiptap::make('Descrizione', 'description'),
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
