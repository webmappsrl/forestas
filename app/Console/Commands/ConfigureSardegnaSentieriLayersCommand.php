<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Import\ImportSardegnaSentieriTrackJob;
use App\Jobs\Import\RecalculateSardegnaSentieriLayersJob;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Wm\WmPackage\Jobs\TaxonomyWhere\RegenerateTaxonomyWhereOutputsJob;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\TaxonomyTerm;

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
 * Un layer che non esiste su questo ambiente è un avvertimento, non un errore: in locale ce ne
 * sono due dei quattro, e far fallire tutto per i mancanti lascerebbe senza filtri anche quelli
 * che ci sono. Si esce in errore solo se non si è potuto scrivere nemmeno una relazione.
 *
 * Le relazioni si scrivono con gli eventi accesi, al contrario di come si faceva con le attività:
 * è l'evento `saving` di `TaxonomyTermable` a copiare la tassonomia su `parent_id`, e senza di
 * lui le righe resterebbero orfane della loro famiglia. L'observer che reindicizza non costa
 * nulla qui, perché un Layer non è Searchable ed esce subito. Il ricalcolo si accoda una volta
 * sola alla fine — con `--no-recalc` nemmeno quello, perché chi chiama (l'import) ha già la sua
 * catena di fine batch.
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
        $risolti = [];
        $mancanti = 0;

        foreach (self::LAYER_TERMS as $nome => $identifiers) {
            // Il nome del layer si confronta in PHP: la colonna `name` non è jsonb.
            $layers = Layer::query()
                ->where('app_id', SardegnaSentieriImportService::IMPORT_APP_ID)
                ->get()
                ->filter(fn (Layer $layer) => $layer->getTranslation('name', 'it', false) === $nome)
                ->values();

            if ($layers->count() !== 1) {
                $this->warn("Layer «{$nome}»: trovati {$layers->count()}, ne serve esattamente uno. Lo salto.");
                $mancanti++;

                continue;
            }

            $termini = TaxonomyTerm::query()->whereIn('identifier', $identifiers)->get();

            if ($termini->count() !== count($identifiers)) {
                $trovati = $termini->pluck('identifier')->all();
                $assenti = implode(', ', array_diff($identifiers, $trovati));
                $this->error("Layer «{$nome}»: termini mancanti ({$assenti})."
                    .' Lancia prima `sardegnasentieri:configure-taxonomy-terms`.');
                $this->error('Nessuna relazione scritta.');

                return self::FAILURE;
            }

            $risolti[$nome] = [$layers->first(), $termini];
        }

        if ($risolti === []) {
            $this->error('Nessun layer di tipologia trovato su questo ambiente: nessuna relazione scritta.');

            return self::FAILURE;
        }

        foreach ($risolti as $nome => [$layer, $termini]) {
            $esito = $layer->taxonomyTerms()->sync($termini->pluck('id')->all());
            $cambiati = count($esito['attached']) + count($esito['detached']);
            $elenco = $termini->pluck('identifier')->implode(', ');
            $this->line($cambiati > 0
                ? "Layer «{$nome}»: legato a {$elenco} (aggiunti ".count($esito['attached']).', rimossi '.count($esito['detached']).').'
                : "Layer «{$nome}»: già a posto.");
        }

        if ($mancanti > 0) {
            $this->warn("Layer saltati perché assenti su questo ambiente: {$mancanti}.");
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
}
