<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Jobs\Import\RecalculateSardegnaSentieriLayersJob;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyActivityable;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Models\TaxonomyWhereable;

/**
 * Lega ogni layer di tipologia dell'app di import ai termini che lo definiscono (oc:8910).
 *
 * Prima legava i layer alle attività storiche (`sardegnasentieri:type:*`), che il modello unico
 * ha sostituito: oggi «Sentiero», «Itinerario» e «Cammino» sono termini di «Tipo percorso», e
 * «accessibile» non è un'attività ma la conseguenza di avere una difficoltà di accessibilità —
 * per questo il layer dei percorsi accessibili prende tutti e tre i termini della scala: dentro
 * la stessa tassonomia il filtro è in OR (oc:8910).
 *
 * Lo chiama l'import con `--reset`, quindi su collaudo gira ogni notte da solo: è idempotente e
 * non va più lanciato a mano dopo il reset.
 *
 * **Questo comando è la definizione dei filtri di quei quattro layer**: quello che non è scritto
 * in `LAYER_TERMS` non deve stare sul layer. Un criterio in più non si somma, si moltiplica —
 * `LayerService` mette attività, zone e termini in AND — quindi un'attività rimasta dalla versione
 * precedente di questo comando taglia il layer in silenzio: su un ambiente dove quella girava,
 * «Sentieri» avrebbe il termine `route-type-trail` *e* l'attività `sardegnasentieri:type:sentiero`,
 * e terrebbe la sola intersezione. Per questo le altre tassonomie del layer si staccano. Se un
 * criterio deve restare, si aggiunge qui, non a mano sulla scheda.
 *
 * Il layer che non esiste viene creato: è l'unico modo perché la configurazione sia la stessa su
 * tutti gli ambienti senza passare da Nova.
 *
 * I termini si scrivono con gli eventi accesi: è l'evento `saving` di `TaxonomyTermable` a copiare
 * la tassonomia su `parent_id`, e senza di lui le righe resterebbero orfane della loro famiglia.
 * L'observer che reindicizza non costa nulla lì, perché un Layer non è Searchable ed esce subito.
 * Le altre tassonomie si staccano invece **senza** eventi: `TaxonomyActivityablesObserver`
 * riassegnerebbe le tracce riga per riga, e ogni riga di `layerables` accoda una catena PBF e un
 * job di geometria — centinaia, dentro un comando artisan. Il ricalcolo si accoda una volta sola
 * alla fine — con `--no-recalc` nemmeno quello, perché chi chiama (l'import) ha già la sua catena
 * di fine batch.
 */
class ConfigureSardegnaSentieriLayersCommand extends Command
{
    /** Nome italiano del layer => identifier dei termini che lo definiscono (in OR fra loro). */
    public const LAYER_TERMS = [
        'Sentieri' => ['route-type-trail'],
        'Itinerari' => ['route-type-itinerary'],
        'Cammini di Sardegna' => ['route-type-walk'],
        'Sentieri Accessibili' => [
            'inclusivity-difficulty-at',
            'inclusivity-difficulty-ae',
            'inclusivity-difficulty-aee',
        ],
    ];

    protected $signature = 'sardegnasentieri:configure-layers
        {--no-recalc : Non accodare ricalcolo dei layer e reindex}';

    protected $description = 'Associa ogni layer di tipologia di Sardegna Sentieri ai suoi termini e accoda il ricalcolo';

    public function handle(): int
    {
        $resolved = [];
        $created = 0;
        $ambiguous = 0;

        // Tutti i layer dell'app in una lettura sola: il nome si confronta in PHP, perché la
        // colonna `name` non è jsonb.
        $existing = Layer::query()
            ->where('app_id', SardegnaSentieriImportService::IMPORT_APP_ID)
            ->get();

        foreach (self::LAYER_TERMS as $name => $identifiers) {
            $terms = TaxonomyTerm::query()->whereIn('identifier', $identifiers)->get();

            if ($terms->count() !== count($identifiers)) {
                $found = $terms->pluck('identifier')->all();
                $missing = implode(', ', array_diff($identifiers, $found));
                $this->error("Layer «{$name}»: termini mancanti ({$missing})."
                    .' Lancia prima `sardegnasentieri:configure-taxonomy-terms`.');
                $this->error('Nessuna relazione scritta.');

                return self::FAILURE;
            }

            $layers = $existing
                ->filter(fn (Layer $layer) => $layer->getTranslation('name', 'it', false) === $name)
                ->values();

            // Due layer con lo stesso nome: non si indovina quale configurare, e crearne un terzo
            // peggiorerebbe la situazione. Si salta questo e si configurano gli altri, che senza
            // filtri resterebbero una mappa vuota.
            if ($layers->count() > 1) {
                $this->warn("Layer «{$name}»: trovati {$layers->count()}, ne serve esattamente uno. Lo salto.");
                $ambiguous++;

                continue;
            }

            $layer = $layers->first() ?? $this->createLayer($name);

            if ($layers->isEmpty()) {
                $created++;
                $this->line("Layer «{$name}»: non c'era, creato (id {$layer->id}).");
            }

            $resolved[$name] = [$layer, $terms];
        }

        if ($resolved === []) {
            $this->error('Nessun layer di tipologia configurabile su questo ambiente: nessuna relazione scritta.');

            return self::FAILURE;
        }

        foreach ($resolved as $name => [$layer, $terms]) {
            $outcome = $layer->taxonomyTerms()->sync($terms->pluck('id')->all());
            $changed = count($outcome['attached']) + count($outcome['detached']);
            $list = $terms->pluck('identifier')->implode(', ');
            $this->line($changed > 0
                ? "Layer «{$name}»: legato a {$list} (aggiunti ".count($outcome['attached']).', rimossi '.count($outcome['detached']).').'
                : "Layer «{$name}»: già a posto.");

            $this->detachOtherTaxonomies($layer, $name);
        }

        if ($created > 0) {
            $this->info("Layer creati su questo ambiente: {$created}.");
        }

        if ($ambiguous > 0) {
            $this->warn("Layer saltati perché il nome non è univoco: {$ambiguous}.");
        }

        if ($this->option('no-recalc')) {
            return self::SUCCESS;
        }

        Bus::chain([
            new RecalculateSardegnaSentieriLayersJob,
            new RegenerateTaxonomyWhereOutputsJob(SardegnaSentieriImportService::IMPORT_APP_ID),
        ])->onQueue(ImportSardegnaSentieriTrackJob::QUEUE)->dispatch();

        $this->info('Ricalcolo dei layer e reindex accodati.');

        return self::SUCCESS;
    }

    /**
     * Crea il layer mancante, in modalità automatica e senza geometria.
     *
     * `track_mode` non si imposta: `isAutoTrackMode()` legge `configuration`, e a `configuration`
     * nulla vale il default `auto` di `wm-package.default_layer_mode`. La geometria è il bbox
     * delle tracce e la scrive il ricalcolo: un layer appena creato non ne ha ancora. Il nome si
     * assegna come array per lingua, perché su un attributo tradotto una stringa finirebbe sotto
     * la locale corrente (oc:8588).
     */
    private function createLayer(string $name): Layer
    {
        $app = App::findOrFail(SardegnaSentieriImportService::IMPORT_APP_ID);

        return Layer::create([
            'name' => ['it' => $name],
            'app_id' => $app->id,
            'user_id' => $app->user_id,
        ]);
    }

    /**
     * Toglie dal layer le tassonomie che non sono i suoi termini: sono criteri in AND che lo
     * tagliano, e qui la configurazione è quella di `LAYER_TERMS`.
     *
     * Senza eventi, e per due motivi diversi. `TaxonomyActivityablesObserver` riassegnerebbe le
     * tracce del layer in sincrono e `LayerableObserver` accoderebbe una catena PBF per ogni riga
     * scritta — su «Sentieri» sarebbero centinaia, dentro un comando artisan. E con `--no-recalc`
     * chi chiama ha chiesto di non accodare niente: il ricalcolo lo fa la sua catena di fine
     * batch, che include già i layer a soli termini.
     */
    private function detachOtherTaxonomies(Layer $layer, string $name): void
    {
        $activities = TaxonomyActivityable::withoutEvents(
            fn () => $layer->taxonomyActivities()->detach()
        );

        $wheres = TaxonomyWhereable::withoutEvents(
            fn () => $layer->taxonomyWheres()->detach()
        );

        if ($activities > 0) {
            $this->line("Layer «{$name}»: staccate {$activities} attività, che filtravano in AND con i termini.");
        }

        if ($wheres > 0) {
            $this->line("Layer «{$name}»: staccate {$wheres} zone, che filtravano in AND con i termini.");
        }
    }
}
