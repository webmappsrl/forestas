<?php

declare(strict_types=1);

namespace App\Services\TrailRegistry\InconsistencyTypes;

use Wm\WmPackage\TrailRegistry\Inconsistencies\InconsistencyTypeDefinition;

/**
 * Un sentiero che il catasto dichiara validato o certificato, e di cui nessuno
 * sa se si cammina (oc:8989).
 *
 * Non e' un errore di nessuno: e' il quadro che il catasto restituisce guardato
 * tutto insieme. Validare un tracciato vuol dire averlo percorso, quindi un
 * sentiero validato senza percorribilita' nota e' un'informazione che manca
 * dove dovrebbe esserci.
 *
 * Il primo giorno saranno la maggior parte dei sentieri: non e' un allarme, e'
 * la lista di lavoro, e si accorcia man mano che Forestas inserisce il dato.
 */
class MissingWalkability implements InconsistencyTypeDefinition
{
    public const TYPE = 'missing_walkability';

    public function label(): string
    {
        return __('Validated trail with unknown walkability');
    }

    public function description(): string
    {
        return __('The registry declares this trail validated or certified, but nobody has recorded whether it can be walked.');
    }
}
