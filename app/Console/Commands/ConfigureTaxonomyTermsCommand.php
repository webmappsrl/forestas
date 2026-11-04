<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Crea le tassonomie e i termini del modello unico delle classificazioni (oc:8910).
 *
 * Senza opzioni allinea: per ogni tassonomia e ogni termine dell'elenco cerca la riga per
 * `identifier`, crea ciò che manca e riallinea nome e icona.
 * Non cambia gli id e non tocca ciò che non è nell'elenco, comprese le tassonomie create a mano da
 * Nova. Con `--fresh` svuota `taxonomy_terms` e ricrea. Con `--dry-run` stampa e non scrive.
 *
 * Si scrive con `insertOrIgnore` più rilettura, mai con `firstOrCreate` né catturando
 * l'eccezione: sotto transazione PostgreSQL una violazione di unicità aborta la transazione
 * (oc:8607).
 *
 * I termini che vengono da Drupal portano `sardegnasentieri:tid:<tid>`; gli altri un identifier
 * semantico, perché la corrispondenza molti-a-uno con Drupal vive nell'import.
 */
class ConfigureTaxonomyTermsCommand extends Command
{
    protected $signature = 'sardegnasentieri:configure-taxonomy-terms
        {--fresh : Svuota taxonomy_terms e ricrea tutto}
        {--dry-run : Stampa cosa farebbe senza scrivere}';

    protected $description = 'Crea (o allinea) le tassonomie e i termini del modello unico delle classificazioni';

    private const DRUPAL = 'sardegnasentieri:tid:';

    /**
     * Elenco di sistema. Termine: [nome, identifier, sort_order, icona|null].
     *
     * @return list<array{identifier: string, icon?: string|null, name: string, single_choice: bool, properties?: array<string, mixed>, terms: list<array{0: string, 1: string, 2: int, 3?: string|null}>}>
     */
    public static function taxonomies(): array
    {
        $d = fn (int $tid): string => self::DRUPAL.$tid;

        return [
            ['identifier' => 'hiking-difficulty', 'icon' => 'txn-trekking', 'name' => 'Difficoltà escursionistica', 'single_choice' => true, 'properties' => ['models' => ['EcTrack'], 'tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['hiking']]], 'terms' => [
                ['T', $d(4066), 0], ['E', $d(4067), 1], ['EE', $d(4068), 2], ['EEA', $d(4069), 3],
            ]],
            ['identifier' => 'mtb-difficulty', 'icon' => 'txn-mtb', 'name' => 'Difficoltà mountain bike', 'single_choice' => true, 'properties' => ['models' => ['EcTrack'], 'tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['mtb']]], 'terms' => [
                ['TC', $d(4908), 0], ['MC', $d(4909), 1], ['BC', $d(4910), 2], ['OC', $d(4911), 3], ['EC', $d(4912), 4],
            ]],
            ['identifier' => 'horse-difficulty', 'icon' => 'txn-horse-riding', 'name' => 'Difficoltà ippovia', 'single_choice' => true, 'properties' => ['models' => ['EcTrack'], 'tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['horse']]], 'terms' => [
                ['Facile', $d(4902), 0], ['Medio', $d(4903), 1], ['Difficile', $d(4904), 2],
            ]],
            ['identifier' => 'nordic-walking-difficulty', 'icon' => 'txn-nordic-walking', 'name' => 'Difficoltà Nordic Walking', 'single_choice' => true, 'properties' => ['models' => ['EcTrack'], 'tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['nordic-walking']]], 'terms' => [
                ['Facile', $d(4905), 0], ['Medio', $d(4906), 1], ['Impegnativo', $d(4907), 2],
            ]],
            ['identifier' => 'soft-mobility-difficulty', 'icon' => 'txn-family', 'name' => 'Difficoltà mobilità dolce', 'single_choice' => true, 'properties' => ['models' => ['EcTrack'], 'tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['hiking']]], 'terms' => [
                ['Facile', $d(4916), 0], ['Medio', $d(4917), 1], ['Difficile', $d(4918), 2],
            ]],
            ['identifier' => 'educational-trails-difficulty', 'icon' => 'txn-environmental-education', 'name' => 'Difficoltà sentiero didattico', 'single_choice' => true, 'properties' => ['models' => ['EcTrack'], 'tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['educational-trails']]], 'terms' => [
                ['Facile', 'educational-trails-difficulty-easy', 0],
                ['Medio', 'educational-trails-difficulty-medium', 1],
                ['Impegnativo', 'educational-trails-difficulty-hard', 2],
            ]],
            // Il tab in cui la tassonomia si mostra sulla scheda di un contenuto: è un dato, non
            // un elenco di identifier nel codice della Resource (oc:8910).
            ['identifier' => 'inclusivity-difficulty', 'icon' => 'txn-wheelchair', 'name' => 'Difficoltà di accessibilità', 'single_choice' => true, 'properties' => ['models' => ['EcTrack'], 'tab' => 'Inclusivity'], 'terms' => [
                ['AT', 'inclusivity-difficulty-at', 0],
                ['AE', 'inclusivity-difficulty-ae', 1],
                ['AEE', 'inclusivity-difficulty-aee', 2],
            ]],
            ['identifier' => 'inclusivity-disabilities', 'icon' => 'txn-mobility-disability', 'name' => 'Disabilità accolte', 'single_choice' => false, 'properties' => ['models' => ['EcTrack'], 'tab' => 'Inclusivity'], 'terms' => [
                ['Fisica e motoria', 'inclusivity-disabilities-physical', 0, 'txn-mobility-disability'],
                ['Sensoriale', 'inclusivity-disabilities-sensory', 1, null],
                ['Cognitiva e psichica', 'inclusivity-disabilities-cognitive', 2, 'txn-cognitive-disability'],
            ]],
            ['identifier' => 'route-type', 'name' => 'Tipo percorso', 'single_choice' => true, 'properties' => ['models' => ['EcTrack']], 'terms' => [
                ['Sentiero', 'route-type-trail', 0],
                ['Itinerario', 'route-type-itinerary', 1],
                ['Cammino', 'route-type-walk', 2],
            ]],
            // Tipo a scelta multipla: 99 nodi Drupal hanno più di una tipologia (oc:8910).
            // Nomi come in Drupal. Il termine 4728 «Cammini di Sardegna» non c'è: è `route-type-walk`.
            // Ordine per usi, dal più frequente.
            // «Tipologia itinerario» ha senso solo su un itinerario o un cammino: su un sentiero
            // il campo si nasconde e il salvataggio lo rifiuta. La regola sta qui, nei dati, e la
            // applica il package: prima era codice in app/Nova/EcTrack.php (oc:8910).
            // Il cammino c'è perché 42 dei 53 cammini hanno anche una tipologia di itinerario.
            ['identifier' => 'itinerary-type', 'name' => 'Tipologia itinerario', 'single_choice' => false, 'properties' => [
                'models' => ['EcTrack'],
                'depends_on' => ['taxonomy' => 'route-type', 'terms' => ['route-type-itinerary', 'route-type-walk']],
            ], 'terms' => [
                ['Itinerari escursionistici di lunga percorrenza (a tappe)', $d(4725), 0],
                ['Itinerari tematici (proposta di visita dei territori)', $d(4727), 1],
                ['Vicino alle Città o attorno ai Borghi', $d(4731), 2],
                ['Passeggiate Geo-Minerarie', $d(4732), 3],
                ['Circuiti di Mountain Bike', $d(4726), 4],
                ['Orienteering ed altro "outdoor"', $d(4730), 5],
                ['Vie della Transumanza', $d(4724), 6],
                ['Itinerari dello Spirito e altre Destinazioni', $d(4729), 7],
            ]],
            // Avvertenze: la tassonomia nasce qui, i termini li porta l'import da Drupal
            // (`sardegnasentieri:warning:<tid>`) e cambiano con la sorgente (oc:8910).
            ['identifier' => 'warnings', 'icon' => 'txn-alert', 'name' => 'Avvertenze', 'single_choice' => false, 'properties' => ['models' => ['EcTrack']], 'terms' => []],
        ];
    }

    /**
     * Tassonomie di sistema ritirati, da togliere dove ancora esistono (oc:8910).
     *
     * Si cancellano solo se sono vuoti: senza termini e senza contenuti collegati. Se qualcuno
     * nel frattempo li ha riempiti, il comando avvisa e li lascia stare — un dato inserito a
     * mano non sparisce per una riga tolta da un elenco.
     *
     * @var array<int, string>
     */
    private const RETIRED = [
        // Nati vuoti e mai riempiti: «Tipo percorso» copre la stessa informazione, e per i
        // tematismi non è mai arrivato l'elenco dei valori.
        'itineraries-and-paths',
        'themes',
    ];

    /**
     * Chiavi cambiate nel tempo: vecchia => nuova (oc:8910).
     *
     * Vanno rinominate, non ricreate: `align()` cerca per identifier, quindi senza questo passo
     * nascerebbe una riga nuova accanto alla vecchia, che resterebbe lì con i suoi termini e i
     * sue relazioni. Una voce si può togliere da qui quando nessun database ha più la chiave
     * vecchia; tenerla non costa niente, perché la rinomina non trova nulla da fare.
     *
     * @var array<string, string>
     */
    private const RENAMED = [
        // Allineata all'identifier dell'attività da cui dipende, `educational-trails`.
        'educational-trail-difficulty' => 'educational-trails-difficulty',
        'educational-trail-difficulty-easy' => 'educational-trails-difficulty-easy',
        'educational-trail-difficulty-medium' => 'educational-trails-difficulty-medium',
        'educational-trail-difficulty-hard' => 'educational-trails-difficulty-hard',
    ];

    /**
     * I nomi italiani delle attività (oc:8910).
     *
     * Le attività vivono in `taxonomy_activities`, tabella storica che l'import di Forestas non
     * tocca: i loro nomi sono rimasti uguali all'identifier, e in Nova — e nelle API — si legge
     * «horse» invece di «Ippovia». Qui si allineano a come si chiamano le scale di difficoltà
     * che dipendono da loro.
     *
     * Non si toccano le righe che un nome vero ce l'hanno già.
     *
     * @var array<string, string>
     */
    private const ACTIVITY_NAMES = [
        'trekking' => 'Trekking',
        'hiking' => 'Escursionismo',
        'mtb' => 'Mountain bike',
        'horse' => 'Ippovia',
        'nordic-walking' => 'Nordic Walking',
        'educational-trails' => 'Sentiero didattico',
        'accessible-trails' => 'Percorsi accessibili',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $fresh = (bool) $this->option('fresh');

        if ($fresh) {
            $this->line(($dry ? '[dry-run] ' : '').'Svuoto taxonomy_terms.');
            if (! $dry) {
                DB::table('taxonomy_terms')->delete();
            }
        }

        $this->renameIdentifiers($dry);
        $this->removeRetired($dry);

        $created = 0;
        $updated = 0;

        foreach (self::taxonomies() as $taxonomy) {
            $taxonomyId = $this->align($taxonomy['identifier'], null, [
                'name' => $taxonomy['name'],
                'single_choice' => $taxonomy['single_choice'],
                'sort_order' => 0,
                'icon' => $taxonomy['icon'] ?? null,
                'properties' => $taxonomy['properties'] ?? null,
            ], $dry, $created, $updated);

            foreach ($taxonomy['terms'] as $term) {
                [$name, $identifier, $order] = $term;
                $icon = $term[3] ?? null;
                $this->align($identifier, $taxonomyId, [
                    'name' => $name,
                    'single_choice' => false,
                    'sort_order' => $order,
                    'icon' => $icon,
                    'properties' => null,
                ], $dry, $created, $updated);
            }
        }

        $renamed = $this->alignActivityNames($dry);
        $enabled = $this->enableForApps($dry);

        $this->info(($dry ? '[dry-run] ' : '')."Creati: {$created}, aggiornati: {$updated}, accesi in un'app: {$enabled}, attività rinominate: {$renamed}.");

        return self::SUCCESS;
    }

    /**
     * Toglie le tassonomie ritirate, se vuote.
     */
    private function removeRetired(bool $dry): void
    {
        foreach (self::RETIRED as $identifier) {
            $row = TaxonomyTerm::query()->where('identifier', $identifier)->first();
            if ($row === null) {
                continue;
            }

            $terms = $row->children()->count();
            $links = DB::table('taxonomy_termables')->where('parent_id', $row->id)->count();
            if ($terms > 0 || $links > 0) {
                $this->warn("Non tolgo {$identifier}: ha {$terms} termini e {$links} contenuti collegati.");

                continue;
            }

            $this->line(($dry ? '[dry-run] ' : '')."Tolgo la tassonomia ritirata {$identifier}");
            if (! $dry) {
                $row->delete();
            }
        }
    }

    /**
     * Applica le rinomine di chiave, prima che l'allineamento crei doppioni.
     *
     * Se la chiave nuova esiste già la vecchia si lascia stare: è il caso di un database già
     * allineato, oppure di due righe nate per strade diverse, che è una cosa da guardare a mano
     * e non da risolvere in silenzio.
     */
    private function renameIdentifiers(bool $dry): void
    {
        foreach (self::RENAMED as $old => $new) {
            $row = TaxonomyTerm::query()->where('identifier', $old)->first();
            if ($row === null) {
                continue;
            }

            if (TaxonomyTerm::query()->where('identifier', $new)->exists()) {
                $this->warn("Esistono sia {$old} sia {$new}: non rinomino, vanno unite a mano.");

                continue;
            }

            $this->line(($dry ? '[dry-run] ' : '')."Rinomino la chiave {$old} -> {$new}");
            if (! $dry) {
                $row->identifier = $new;
                $row->save();
            }
        }
    }

    /**
     * Dà alle attività il loro nome italiano, dove ancora coincide con l'identifier.
     *
     * Attenzione: il nome di un'attività esce nel GeoJSON e nelle API che le app leggono
     * (`properties.taxonomy.activity`). Cambiarlo cambia cosa mostrano: qui è un miglioramento
     * voluto — oggi le app ricevono «horse» — ma è un effetto che esce da Nova.
     */
    private function alignActivityNames(bool $dry): int
    {
        $renamed = 0;

        foreach (self::ACTIVITY_NAMES as $identifier => $name) {
            $activity = TaxonomyActivity::query()->where('identifier', $identifier)->first();
            if ($activity === null) {
                continue;
            }

            $current = $activity->getTranslation('name', 'it', false);
            // Si tocca solo chi non ha ancora un nome proprio: un nome scritto a mano resta.
            if ($current !== '' && $current !== null && $current !== $identifier) {
                continue;
            }

            $this->line(($dry ? '[dry-run] ' : '')."Rinomino l'attività {$identifier}: \"{$current}\" -> \"{$name}\"");
            $renamed++;
            if (! $dry) {
                $activity->setTranslation('name', 'it', $name)->save();
            }
        }

        return $renamed;
    }

    /**
     * Accende le tassonomie di questo shard in tutte le sue app (oc:8910).
     *
     * Sono custom, non di sistema: nascono spenti e nessuno li accenderebbe da solo. Forestas
     * ha una sola app, ma il comando non lo dà per scontato. `syncWithoutDetaching` non tocca
     * ciò che è già acceso e non spegne niente: se qualcuno ha spento una tassonomia in Nova,
     * un rilancio del comando lo riaccende — è il prezzo di un comando che allinea.
     */
    private function enableForApps(bool $dry): int
    {
        $taxonomyIds = TaxonomyTerm::query()
            ->taxonomies()
            ->whereIn('identifier', array_column(self::taxonomies(), 'identifier'))
            ->pluck('id')
            ->all();

        $apps = App::query()->get();
        $enabled = 0;

        foreach ($apps as $app) {
            $missing = array_diff($taxonomyIds, $app->taxonomies()->pluck('taxonomy_terms.id')->all());
            if ($missing === []) {
                continue;
            }
            $this->line(($dry ? '[dry-run] ' : '').'Accendo '.count($missing)." tassonomie nell'app {$app->id}");
            $enabled += count($missing);
            if (! $dry) {
                $app->taxonomies()->syncWithoutDetaching($taxonomyIds);
            }
        }

        return $enabled;
    }

    /**
     * @param  array{name: string, single_choice: bool, sort_order: int, icon: ?string, properties: ?array<string, mixed>}  $attrs
     */
    private function align(string $identifier, ?int $parentId, array $attrs, bool $dry, int &$created, int &$updated): ?int
    {
        $existing = TaxonomyTerm::query()->where('identifier', $identifier)->first();

        if ($existing === null) {
            $this->line(($dry ? '[dry-run] ' : '').'Creo '.($parentId === null ? 'tassonomia' : 'termine')." {$identifier} ({$attrs['name']})");
            $created++;
            if ($dry) {
                return null;
            }

            $now = now();
            DB::table('taxonomy_terms')->insertOrIgnore([
                'parent_id' => $parentId,
                'identifier' => $identifier,
                'name' => json_encode(['it' => $attrs['name']], JSON_UNESCAPED_UNICODE),
                'icon' => $attrs['icon'],
                'sort_order' => $attrs['sort_order'],
                'single_choice' => $attrs['single_choice'],
                'properties' => $attrs['properties'] === null ? null : json_encode($attrs['properties'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return (int) TaxonomyTerm::query()->where('identifier', $identifier)->value('id');
        }

        $current = $existing->getTranslation('name', 'it', false);
        if ($current !== $attrs['name']) {
            $this->line(($dry ? '[dry-run] ' : '')."Riallineo il nome di {$identifier}: \"{$current}\" -> \"{$attrs['name']}\"");
            $updated++;
            if (! $dry) {
                $existing->setTranslation('name', 'it', $attrs['name'])->save();
            }
        }

        if ($existing->icon !== $attrs['icon']) {
            $this->line(($dry ? '[dry-run] ' : '')."Riallineo l'icona di {$identifier}: ".($existing->icon ?? 'nessuna').' -> '.($attrs['icon'] ?? 'nessuna'));
            $updated++;
            if (! $dry) {
                $existing->icon = $attrs['icon'];
                $existing->save();
            }
        }

        // Le properties di sistema (per esempio depends_on) si riallineano; quelle scritte a mano
        // su una riga che non è nell'elenco non vengono toccate, perché align() non la visita.
        if ($attrs['properties'] !== null && $existing->properties !== $attrs['properties']) {
            $this->line(($dry ? '[dry-run] ' : '')."Riallineo le properties di {$identifier}");
            $updated++;
            if (! $dry) {
                $existing->properties = $attrs['properties'];
                $existing->save();
            }
        }

        if ((bool) $existing->single_choice !== $attrs['single_choice'] || (int) $existing->sort_order !== $attrs['sort_order']) {
            $this->line(($dry ? '[dry-run] ' : '')."Riallineo scelta singola e ordine di {$identifier}");
            $updated++;
            if (! $dry) {
                $existing->single_choice = $attrs['single_choice'];
                $existing->sort_order = $attrs['sort_order'];
                $existing->save();
            }
        }

        return $existing->id;
    }
}
