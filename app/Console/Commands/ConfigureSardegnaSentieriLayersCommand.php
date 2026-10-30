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
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyActivityable;

/**
 * Lega ogni layer di tipologia dell'app di import alla sua attività (oc:8827).
 *
 * Si lancia una volta per ambiente. L'attività `sardegnasentieri:cammino` nasce solo quando un
 * tracciato viene reimportato, e l'import orario reimporta solo quelli cambiati in Drupal: la
 * sequenza è deploy, riavvio di Horizon, `sardegnasentieri:import --force` (o il reset notturno
 * dove è attivo), attesa della fine dei job di import, poi questo comando.
 *
 * Risolve prima tutti i layer e tutte le attività: se qualcosa manca o è ambiguo non scrive
 * nulla. Il nome del layer si confronta in PHP: la colonna `name` non è jsonb.
 *
 * I legami si scrivono con `sync()` dentro `TaxonomyActivityable::withoutEvents()`: l'observer
 * del package, su un Layer, farebbe un ricalcolo sincrono e scritture su S3. Il ricalcolo si
 * accoda una volta sola, come a fine import.
 */
class ConfigureSardegnaSentieriLayersCommand extends Command
{
    /** Nome italiano del layer => identifier dell'attività a cui va legato. */
    public const LAYER_ACTIVITIES = [
        'Sentieri' => SardegnaSentieriImportService::TYPE_ACTIVITY_PREFIX.'sentiero',
        'Itinerari' => SardegnaSentieriImportService::TYPE_ACTIVITY_PREFIX.'itinerario',
        'Cammini di Sardegna' => SardegnaSentieriImportService::CAMMINO_ACTIVITY_IDENTIFIER,
        'Sentieri Accessibili' => 'accessible-trails',
    ];

    protected $signature = 'sardegnasentieri:configure-layers';

    protected $description = 'Associa ogni layer di tipologia di Sardegna Sentieri alla sua attività e accoda il ricalcolo';

    public function handle(): int
    {
        $errori = [];
        $risolti = [];

        foreach (self::LAYER_ACTIVITIES as $nome => $identifier) {
            $layers = Layer::query()
                ->where('app_id', SardegnaSentieriImportService::IMPORT_APP_ID)
                ->get()
                ->filter(fn (Layer $layer) => $layer->getTranslation('name', 'it', false) === $nome)
                ->values();
            $attivita = TaxonomyActivity::query()->where('identifier', $identifier)->get();

            if ($layers->count() !== 1) {
                $errori[] = "Layer «{$nome}»: trovati {$layers->count()}, ne serve esattamente uno.";
            }
            if ($attivita->count() !== 1) {
                $errori[] = "Attività «{$identifier}»: trovate {$attivita->count()}, ne serve esattamente una."
                    .' Se manca, lancia prima `sardegnasentieri:import --force` e aspetta la fine dei job.';
            }
            if ($layers->count() === 1 && $attivita->count() === 1) {
                $risolti[$nome] = [$layers->first(), $attivita->first()];
            }
        }

        if ($errori !== []) {
            foreach ($errori as $errore) {
                $this->error($errore);
            }
            $this->error('Nessun legame scritto.');

            return self::FAILURE;
        }

        TaxonomyActivityable::withoutEvents(function () use ($risolti) {
            foreach ($risolti as $nome => [$layer, $attivita]) {
                $esito = $layer->taxonomyActivities()->sync([$attivita->id]);
                $cambiati = count($esito['attached']) + count($esito['detached']);
                $this->line($cambiati > 0
                    ? "Layer «{$nome}»: legato a {$attivita->identifier} (aggiunte ".count($esito['attached']).', rimosse '.count($esito['detached']).').'
                    : "Layer «{$nome}»: già a posto.");
            }
        });

        Bus::chain([
            new RecalculateSardegnaSentieriLayersJob,
            new RegenerateTaxonomyWhereOutputsJob(SardegnaSentieriImportService::IMPORT_APP_ID),
        ])->onQueue(ImportSardegnaSentieriTrackJob::QUEUE)->dispatch();

        $this->info('Ricalcolo dei layer e reindex accodati.');

        return self::SUCCESS;
    }
}
