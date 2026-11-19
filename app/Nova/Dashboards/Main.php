<?php

namespace App\Nova\Dashboards;

use App\Console\Commands\ConfigureTaxonomyTermsCommand;
use App\Models\EcTrack;
use Laravel\Nova\Card;
use Laravel\Nova\Dashboards\Main as Dashboard;
use Wm\WmPackage\TrailRegistry\Nova\Cards\TaxonomyDistributionCard;
use Wm\WmPackage\TrailRegistry\Nova\Cards\TrailRegistryInconsistenciesCard;
use Wm\WmPackage\TrailRegistry\Nova\Cards\WalkabilityDistributionCard;

class Main extends Dashboard
{
    /**
     * Get the cards for the dashboard.
     *
     * @return array<int, Card>
     */
    public function cards(): array
    {
        // Lo stato del Catasto al posto della pagina di benvenuto di Nova
        // (oc:8989). Le card vengono dal package: qui si montano soltanto.
        //
        // Nessun `canSee` per ruolo: sono numeri d'insieme, non dati di un
        // singolo contenuto, e in Nova entrano solo i ruoli che li possono
        // vedere — `Guest` e `Sus` li ferma il gate.
        return [
            // La validazione prima: è l'asse della pratica catastale, quello su cui
            // Forestas lavora da anni. La percorribilità è il dato nuovo, e gli sta
            // accanto perché i due si leggono insieme (oc:8989).
            new TaxonomyDistributionCard(
                EcTrack::VALIDATION_STATUS_TAXONOMY,
                __('Trail validation status'),
                null,
                null,
                // Solo gli stati dichiarati: i due di terreno sono usciti da questa famiglia
                // (oc:8989) e in tabella restano finché la storia li nomina, perché le chiavi
                // esterne degli eventi sono senza cascata. Qui comparirebbero a zero.
                ConfigureTaxonomyTermsCommand::termIdentifiersOf(EcTrack::VALIDATION_STATUS_TAXONOMY),
            ),
            new WalkabilityDistributionCard,
            new TrailRegistryInconsistenciesCard,
        ];
    }
}
