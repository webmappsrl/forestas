<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EcTrack;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityService;

/**
 * Porta lo stato di validazione dalla colonna `ec_tracks.stato_validazione` al termine della
 * famiglia `validation-status` (oc:8932), e poi toglie la colonna.
 *
 * Serve una volta sola, a mano, su un database che la colonna ce l'ha ancora (UAT, dev, locale
 * vecchio): una installazione nuova non l'ha mai avuta, perché la migration che la aggiungeva è
 * stata tolta. Non è una migration perché il repo modifica la `create` invece di aggiungerne, e
 * i database esistenti li aggiorna il team a mano: questo è il passo a mano. Quando nessun
 * database ha più la colonna, il comando si cancella.
 *
 * Prima va lanciato `sardegnasentieri:configure-taxonomy-terms`, che crea la famiglia e i
 * termini. Il comando è rilanciabile: una traccia che ha già un termine di stato non si tocca, e
 * la colonna si toglie solo se nessun valore è rimasto indietro. Dopo, serve un reindex
 * (`scout:import` o l'import orario): lo spostamento non sveglia Elasticsearch.
 */
class MoveValidationStatusToTermsCommand extends Command
{
    protected $signature = 'sardegnasentieri:move-validation-status
        {--dry-run : Stampa cosa farebbe senza scrivere}';

    protected $description = 'Sposta lo stato di validazione dalla colonna al termine della famiglia validation-status e toglie la colonna';

    /**
     * Valore della vecchia colonna (i casi dell'enum `StatoValidazione`, ritirato) => identifier
     * del termine.
     *
     * @var array<string, string>
     */
    private const LEGACY_VALUES = [
        'non_verificato' => 'validation-status-unverified',
        'in_revisione_validazione' => 'validation-status-under-review',
        'in_pre_accatastamento' => 'validation-status-pre-registration',
        'validato' => 'validation-status-validated',
        'certificato' => 'validation-status-certified',
    ];

    /**
     * I due valori che non erano stati della pratica ma del terreno: dalla colonna vanno dritti
     * nella famiglia della percorribilità, che è dove vivono da oc:8989, senza passare per i
     * termini intermedi — che la famiglia della validazione non dichiara più.
     *
     * La pratica, per questi sentieri, resta «Non verificato»: dal valore della colonna non si
     * ricava a che punto fosse.
     *
     * @var array<string, string>
     */
    private const TERRAIN_VALUES = [
        'percorribile' => 'walkability-status-walkable',
        'non_percorribile' => 'walkability-status-not-walkable',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if (! Schema::hasColumn('ec_tracks', 'stato_validazione')) {
            $this->info('La colonna stato_validazione non c\'è: niente da spostare.');

            return self::SUCCESS;
        }

        $family = TaxonomyTerm::query()->taxonomies()->where('identifier', EcTrack::VALIDATION_STATUS_TAXONOMY)->first();
        $terms = $family?->children()->pluck('id', 'identifier') ?? collect();
        $missing = collect(self::LEGACY_VALUES)->reject(fn (string $identifier) => $terms->has($identifier));
        if ($family === null || $missing->isNotEmpty()) {
            $this->error('Mancano la famiglia o i suoi termini: lancia prima sardegnasentieri:configure-taxonomy-terms.');

            return self::FAILURE;
        }

        $moved = 0;
        $already = 0;
        $unknown = [];

        $rows = DB::table('ec_tracks')->whereNotNull('stato_validazione')->where('stato_validazione', '!=', '')
            ->orderBy('id')->get(['id', 'stato_validazione']);

        foreach ($rows as $row) {
            $walkability = self::TERRAIN_VALUES[$row->stato_validazione] ?? null;
            $identifier = $walkability !== null
                ? 'validation-status-unverified'
                : (self::LEGACY_VALUES[$row->stato_validazione] ?? null);
            if ($identifier === null) {
                $unknown[$row->stato_validazione] = ($unknown[$row->stato_validazione] ?? 0) + 1;

                continue;
            }

            $track = EcTrack::query()->find($row->id);
            if ($track === null) {
                continue;
            }

            if ($track->taxonomyTerms()->where('taxonomy_terms.parent_id', $family->id)->exists()) {
                $already++;

                continue;
            }

            $moved++;
            if (! $dry) {
                WmEcTrack::withoutSyncingToSearch(fn () => EcTrack::withoutSyncingToSearch(
                    function () use ($track, $terms, $identifier, $walkability) {
                        $track->taxonomyTerms()->attach($terms[$identifier]);

                        // Passa dal service perché il passaggio resti nello storico con la sua
                        // provenienza: è una migrazione, non un inserimento di qualcuno.
                        if ($walkability !== null) {
                            app(WalkabilityService::class)->set(
                                $track,
                                $walkability,
                                null,
                                null,
                                TrailStatusEventSource::Migration,
                            );
                        }
                    }
                ));
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')."Spostati: {$moved}, già con un termine: {$already}.");

        if ($unknown !== []) {
            foreach ($unknown as $value => $count) {
                $this->warn("Valore sconosciuto \"{$value}\" su {$count} tracce: non spostato, la colonna resta.");
            }

            return self::FAILURE;
        }

        $this->line(($dry ? '[dry-run] ' : '').'Tolgo la colonna stato_validazione.');
        if (! $dry) {
            Schema::table('ec_tracks', fn ($table) => $table->dropColumn('stato_validazione'));
        }

        return self::SUCCESS;
    }
}
