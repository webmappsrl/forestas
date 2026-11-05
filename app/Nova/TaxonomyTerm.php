<?php

namespace App\Nova;

use Wm\WmPackage\Nova\TaxonomyTerm as WmTaxonomyTerm;

/**
 * Termini e tassonomie del modello unico delle classificazioni (oc:8910).
 *
 * La Resource vive nel package: qui la si estende solo per registrarla su Forestas e dare le
 * etichette in italiano. Il NovaServiceProvider dello shard registra `app/Nova`, non `src/Nova`
 * del package, quindi senza questa sottoclasse la Resource non sarebbe raggiungibile.
 */
class TaxonomyTerm extends WmTaxonomyTerm
{
    /**
     * Le liste dei contenuti puntano alle Resource dello shard: il package registra le proprie in
     * `src/Nova`, che qui non sono raggiungibili.
     */
    protected function ecTrackResource(): string
    {
        return EcTrack::class;
    }

    protected function ecPoiResource(): string
    {
        return EcPoi::class;
    }

    public static function label(): string
    {
        return __('Taxonomy terms');
    }

    public static function singularLabel(): string
    {
        return __('Taxonomy term');
    }
}
