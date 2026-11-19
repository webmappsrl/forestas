<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EcTrack;
use Illuminate\Console\Command;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityService;

/**
 * Porta i sentieri dalla vecchia classificazione unica ai due assi (oc:8989).
 *
 * In Drupal lo stato era uno solo, e due dei suoi sette valori parlavano del
 * terreno invece che della pratica catastale: «Percorribile» e «Non
 * percorribile». Qui quei sentieri ricevono lo stato di percorribilita'
 * corrispondente, e la loro validazione diventa «Non verificato» — scritta, non
 * lasciata vuota, perche' un sentiero senza termine di validazione esce da ogni
 * filtro e dai conteggi. Tutti gli altri ricevono «Sconosciuta»: la
 * percorribilita' non si deduce dalla pratica.
 *
 * **Lavora per sentiero e non rinomina i termini.** I termini della nuova
 * famiglia esistono gia' — li crea il package quando si accende il dominio — e
 * `ConfigureTaxonomyTermsCommand::renameIdentifiers()`, trovando sia il vecchio
 * sia il nuovo identifier, salta la rinomina con un avvertimento in un log che
 * nessuno legge, lasciando i sentieri attaccati dov'erano.
 *
 * Serve una volta sola, ed e' rilanciabile: un sentiero che ha gia' una
 * percorribilita' non si tocca. Dopo serve un reindex, perche' lo spostamento
 * dei legami non sveglia Elasticsearch da se' su tutte le tracce.
 */
class MoveWalkabilityFromValidationCommand extends Command
{
    protected $signature = 'sardegnasentieri:move-walkability
        {--dry-run : Stampa cosa farebbe senza scrivere}';

    protected $description = 'Porta i due stati di terreno da validation-status alla famiglia della percorribilità';

    /**
     * I due termini che cambiano famiglia: identifier nella validazione =>
     * identifier nella percorribilita'.
     *
     * @var array<string, string>
     */
    private const MOVED = [
        'validation-status-walkable' => 'walkability-status-walkable',
        'validation-status-not-walkable' => 'walkability-status-not-walkable',
    ];

    private const UNKNOWN = 'walkability-status-unknown';

    private const UNVERIFIED = 'validation-status-unverified';

    public function handle(WalkabilityService $walkability): int
    {
        $dry = (bool) $this->option('dry-run');

        $moved = 0;
        $unknown = 0;

        EcTrack::query()->chunkById(200, function ($tracks) use ($walkability, $dry, &$moved, &$unknown) {
            foreach ($tracks as $track) {
                // Rilanciabile: chi ha già uno stato non si tocca.
                if ($walkability->current($track) !== null) {
                    continue;
                }

                $from = $this->groundTermOf($track);

                if ($from === null) {
                    if (! $dry) {
                        $walkability->set($track, self::UNKNOWN, null, null, TrailStatusEventSource::Migration);
                    }
                    $unknown++;

                    continue;
                }

                if (! $dry) {
                    $walkability->set($track, self::MOVED[$from->identifier], null, null, TrailStatusEventSource::Migration);
                    $this->replaceValidationWithUnverified($track, $from);
                }
                $moved++;
            }
        });

        $this->info(($dry ? '[dry-run] ' : '')."Spostati: {$moved}. Senza percorribilità nota: {$unknown}.");

        if (! $dry) {
            $this->warn('Serve un reindex (scout:import o il prossimo import) perché la ricerca veda i nuovi termini.');
        }

        return self::SUCCESS;
    }

    /** Il termine di terreno che il sentiero porta dentro `validation-status`, se ce l'ha. */
    private function groundTermOf(EcTrack $track): ?TaxonomyTerm
    {
        return $track->taxonomyTerms()
            ->whereIn('taxonomy_terms.identifier', array_keys(self::MOVED))
            ->first();
    }

    /**
     * Drupal non ha mai detto a che punto fosse la pratica di questi sentieri:
     * aveva un campo solo e l'ha speso per il terreno. «Non verificato» e' la
     * verita', e va scritta — senza, quei sentieri resterebbero senza alcun
     * termine di validazione.
     */
    private function replaceValidationWithUnverified(EcTrack $track, TaxonomyTerm $from): void
    {
        $unverified = TaxonomyTerm::query()->where('identifier', self::UNVERIFIED)->first();

        $track->taxonomyTerms()->detach($from->id);

        if ($unverified !== null) {
            $track->taxonomyTerms()->syncWithoutDetaching([$unverified->id]);
        }

        $track->unsetRelation('taxonomyTerms');
    }
}
