<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 * Ogni termine ha un identifier nostro: per le scale di difficoltà è il valore che il SUS manda
 * nel campo 37 della domanda (`mtb-difficulty-BC`, `horse-difficulty-easy`), per gli altri un
 * nome semantico. Il term id di Drupal, che prima faceva da identifier, resta in
 * `properties->source->drupal_tid`: è un dato di provenienza, e l'import ci riconosce il termine
 * quando la sorgente lo cita. Le chiavi vecchie si rinominano, vedi `RENAMED`.
 */
class ConfigureTaxonomyTermsCommand extends Command
{
    protected $signature = 'sardegnasentieri:configure-taxonomy-terms
        {--fresh : Svuota taxonomy_terms e ricrea tutto}
        {--dry-run : Stampa cosa farebbe senza scrivere}';

    protected $description = 'Crea (o allinea) le tassonomie e i termini del modello unico delle classificazioni';

    /**
     * Elenco di sistema. Termine: [nome, identifier, sort_order, icona|null, tid di Drupal|null].
     *
     * L'identifier e' il nome che diamo noi al termine, e per le scale di difficolta' e' il
     * valore che il SUS manda nel campo 37: guardando la riga si sa cosa arriva dall'API. Il tid
     * di Drupal, che prima era l'identifier, resta scritto in `properties->source->drupal_tid`:
     * serve all'import per riconoscere il termine alla fonte, ed e' un dato di provenienza, non
     * il nome della cosa. Quando Drupal verra' dismesso, sparisce senza toccare altro.
     *
     * @return list<array{identifier: string, icon?: string|null, name: string, single_choice: bool, properties?: array<string, mixed>, terms: list<array{0: string, 1: string, 2: int, 3?: string|null, 4?: int|null}>}>
     */
    public static function taxonomies(): array
    {
        return [
            ['identifier' => 'hiking-difficulty', 'icon' => 'txn-trekking', 'name' => 'Difficoltà escursionistica', 'single_choice' => true, 'properties' => ['tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['hiking']]], 'terms' => [
                ['T', 'hiking-difficulty-T', 0, null, 4066], ['E', 'hiking-difficulty-E', 1, null, 4067],
                ['EE', 'hiking-difficulty-EE', 2, null, 4068], ['EEA', 'hiking-difficulty-EEA', 3, null, 4069],
            ]],
            ['identifier' => 'mtb-difficulty', 'icon' => 'txn-mtb', 'name' => 'Difficoltà mountain bike', 'single_choice' => true, 'properties' => ['tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['mtb']]], 'terms' => [
                ['TC', 'mtb-difficulty-TC', 0, null, 4908], ['MC', 'mtb-difficulty-MC', 1, null, 4909],
                ['BC', 'mtb-difficulty-BC', 2, null, 4910], ['OC', 'mtb-difficulty-OC', 3, null, 4911],
                ['EC', 'mtb-difficulty-EC', 4, null, 4912],
            ]],
            ['identifier' => 'horse-difficulty', 'icon' => 'txn-horse-riding', 'name' => 'Difficoltà ippovia', 'single_choice' => true, 'properties' => ['tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['horse']]], 'terms' => [
                ['Facile', 'horse-difficulty-easy', 0, null, 4902],
                ['Medio', 'horse-difficulty-medium', 1, null, 4903],
                ['Difficile', 'horse-difficulty-hard', 2, null, 4904],
            ]],
            ['identifier' => 'nordic-walking-difficulty', 'icon' => 'txn-nordic-walking', 'name' => 'Difficoltà Nordic Walking', 'single_choice' => true, 'properties' => ['tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['nordic-walking']]], 'terms' => [
                ['Facile', 'nordic-walking-difficulty-easy', 0, null, 4905],
                ['Medio', 'nordic-walking-difficulty-medium', 1, null, 4906],
                ['Impegnativo', 'nordic-walking-difficulty-hard', 2, null, 4907],
            ]],
            ['identifier' => 'soft-mobility-difficulty', 'icon' => 'txn-family', 'name' => 'Difficoltà mobilità dolce', 'single_choice' => true, 'properties' => ['tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['hiking']]], 'terms' => [
                ['Facile', 'soft-mobility-difficulty-easy', 0, null, 4916],
                ['Medio', 'soft-mobility-difficulty-medium', 1, null, 4917],
                ['Difficile', 'soft-mobility-difficulty-hard', 2, null, 4918],
            ]],
            ['identifier' => 'educational-trails-difficulty', 'icon' => 'txn-environmental-education', 'name' => 'Difficoltà sentiero didattico', 'single_choice' => true, 'properties' => ['tab' => 'Activities', 'depends_on' => ['legacy' => 'activities', 'terms' => ['educational-trails']]], 'terms' => [
                ['Facile', 'educational-trails-difficulty-easy', 0],
                ['Medio', 'educational-trails-difficulty-medium', 1],
                ['Impegnativo', 'educational-trails-difficulty-hard', 2],
            ]],
            // Il tab in cui la tassonomia si mostra sulla scheda di un contenuto: è un dato, non
            // un elenco di identifier nel codice della Resource (oc:8910).
            ['identifier' => 'inclusivity-difficulty', 'icon' => 'txn-wheelchair', 'name' => 'Difficoltà di accessibilità', 'single_choice' => true, 'properties' => ['tab' => 'Inclusivity'], 'terms' => [
                ['AT', 'inclusivity-difficulty-AT', 0],
                ['AE', 'inclusivity-difficulty-AE', 1],
                ['AEE', 'inclusivity-difficulty-AEE', 2],
            ]],
            ['identifier' => 'inclusivity-disabilities', 'icon' => 'txn-mobility-disability', 'name' => 'Disabilità accolte', 'single_choice' => false, 'properties' => ['tab' => 'Inclusivity'], 'terms' => [
                ['Fisica e motoria', 'inclusivity-disabilities-physical', 0, 'txn-mobility-disability'],
                ['Sensoriale', 'inclusivity-disabilities-sensory', 1, null],
                ['Cognitiva e psichica', 'inclusivity-disabilities-cognitive', 2, 'txn-cognitive-disability'],
            ]],
            ['identifier' => 'route-type', 'name' => 'Tipo percorso', 'single_choice' => true, 'terms' => [
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
                'depends_on' => ['taxonomy' => 'route-type', 'terms' => ['route-type-itinerary', 'route-type-walk']],
            ], 'terms' => [
                ['Itinerari escursionistici di lunga percorrenza (a tappe)', 'itinerary-type-long-distance', 0, null, 4725],
                ['Itinerari tematici (proposta di visita dei territori)', 'itinerary-type-thematic', 1, null, 4727],
                ['Vicino alle Città o attorno ai Borghi', 'itinerary-type-urban', 2, null, 4731],
                ['Passeggiate Geo-Minerarie', 'itinerary-type-geomining', 3, null, 4732],
                ['Circuiti di Mountain Bike', 'itinerary-type-mtb', 4, null, 4726],
                ['Orienteering ed altro "outdoor"', 'itinerary-type-orienteering', 5, null, 4730],
                ['Vie della Transumanza', 'itinerary-type-transhumance', 6, null, 4724],
                ['Itinerari dello Spirito e altre Destinazioni', 'itinerary-type-spiritual', 7, null, 4729],
            ]],
            // Avvertenze: la tassonomia nasce qui, i termini li porta l'import. Non si
            // duplicano in questo elenco: la sorgente del dato e' Drupal, e una copia qui
            // sarebbe un derivato che prima o poi dice una cosa diversa dall'originale. Quello
            // che resta nostro e' il nome: l'import lo ricava dal nome inglese, o dalla
            // mappatura in `SardegnaSentieriImportService`, mai dal tid (oc:8910).
            ['identifier' => 'warnings', 'icon' => 'txn-alert', 'name' => 'Avvertenze', 'single_choice' => false, 'terms' => []],
            // Stato di validazione del percorso (oc:8932): era la colonna `stato_validazione` con un
            // enum. Uno stato solo per percorso. I termini hanno un identifier semantico perché
            // chi legge il dato (il semaforo del sito) lo confronta come chiave: la
            // corrispondenza con i tid di Drupal vive nell'import
            // (`SardegnaSentieriImportService::VALIDATION_STATUS_TERMS`). L'ordine è quello del
            // vecchio enum.
            ['identifier' => 'validation-status', 'name' => 'Stato validazione', 'single_choice' => true, 'properties' => ['tab' => 'Status'], 'terms' => [
                // «Non percorribile» e «Percorribile» non sono piu' qui (oc:8989):
                // parlavano del terreno, non della pratica, e sono passati alla
                // famiglia della percorribilita', che crea il package quando si
                // accende il dominio. I sentieri che li portavano li sposta
                // `sardegnasentieri:move-walkability`, da lanciare **prima** di
                // questa modifica su un database che li ha ancora.
                ['Non verificato', 'validation-status-unverified', 0],
                ['In revisione-validazione', 'validation-status-under-review', 2],
                ['In pre-accatastamento o manutenzione', 'validation-status-pre-registration', 3],
                ['Validato', 'validation-status-validated', 5],
                ['Certificato', 'validation-status-certified', 6],
            ]],
        ];
    }

    /**
     * Gli identifier dei termini che il comando dichiara per una famiglia (oc:8989).
     *
     * È la sola fonte di verità su quali stati esistono: la tabella può contenere righe rimaste
     * da una configurazione precedente, e chi offre delle scelte all'utente deve offrire queste,
     * non quelle.
     *
     * @return list<string>
     */
    public static function termIdentifiersOf(string $taxonomyIdentifier): array
    {
        foreach (self::taxonomies() as $taxonomy) {
            if ($taxonomy['identifier'] !== $taxonomyIdentifier) {
                continue;
            }

            return array_map(fn (array $term) => $term[1], $taxonomy['terms']);
        }

        return [];
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
     * Termini ritirati: righe figlie che non devono più esistere (oc:8989).
     *
     * Diverso da `RETIRED`, che toglie intere famiglie: qui si tolgono singoli termini da una
     * famiglia che resta. I due valori di terreno sono usciti da `validation-status` quando la
     * percorribilità è diventata un dato a sé; finché restano in tabella compaiono a zero nei
     * conteggi e nei filtri, come scelte che non selezionano niente.
     *
     * Si tolgono solo se nessuno li usa più: la migrazione dei sentieri (`move-walkability`) deve
     * essere già passata. Se qualcosa li tiene, il comando lo dice e li lascia dove sono.
     *
     * @var list<string>
     */
    private const RETIRED_TERMS = [
        'validation-status-walkable',
        'validation-status-not-walkable',
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

        // Le scale di difficolta' sono il valore che il SUS manda nel campo 37 della domanda:
        // l'identifier e' quel valore, e il tid di Drupal — che prima faceva da identifier —
        // resta in `properties->source->drupal_tid` come dato di provenienza.
        'sardegnasentieri:tid:4066' => 'hiking-difficulty-T',
        'sardegnasentieri:tid:4067' => 'hiking-difficulty-E',
        'sardegnasentieri:tid:4068' => 'hiking-difficulty-EE',
        'sardegnasentieri:tid:4069' => 'hiking-difficulty-EEA',
        'sardegnasentieri:tid:4908' => 'mtb-difficulty-TC',
        'sardegnasentieri:tid:4909' => 'mtb-difficulty-MC',
        'sardegnasentieri:tid:4910' => 'mtb-difficulty-BC',
        'sardegnasentieri:tid:4911' => 'mtb-difficulty-OC',
        'sardegnasentieri:tid:4912' => 'mtb-difficulty-EC',
        'sardegnasentieri:tid:4902' => 'horse-difficulty-easy',
        'sardegnasentieri:tid:4903' => 'horse-difficulty-medium',
        'sardegnasentieri:tid:4904' => 'horse-difficulty-hard',
        'sardegnasentieri:tid:4905' => 'nordic-walking-difficulty-easy',
        'sardegnasentieri:tid:4906' => 'nordic-walking-difficulty-medium',
        'sardegnasentieri:tid:4907' => 'nordic-walking-difficulty-hard',
        'sardegnasentieri:tid:4916' => 'soft-mobility-difficulty-easy',
        'sardegnasentieri:tid:4917' => 'soft-mobility-difficulty-medium',
        // I gradi di accessibilita' erano gia' parlanti, ma minuscoli: ora l'identifier e' il
        // valore dell'API, che e' la sigla com'e' scritta nel format.
        'inclusivity-difficulty-at' => 'inclusivity-difficulty-AT',
        'inclusivity-difficulty-ae' => 'inclusivity-difficulty-AE',
        'inclusivity-difficulty-aee' => 'inclusivity-difficulty-AEE',

        // Tipologie di itinerario e avvertenze: il nome e' nostro, il tid resta in `properties`.
        'sardegnasentieri:tid:4725' => 'itinerary-type-long-distance',
        'sardegnasentieri:tid:4727' => 'itinerary-type-thematic',
        'sardegnasentieri:tid:4731' => 'itinerary-type-urban',
        'sardegnasentieri:tid:4732' => 'itinerary-type-geomining',
        'sardegnasentieri:tid:4726' => 'itinerary-type-mtb',
        'sardegnasentieri:tid:4730' => 'itinerary-type-orienteering',
        'sardegnasentieri:tid:4724' => 'itinerary-type-transhumance',
        'sardegnasentieri:tid:4729' => 'itinerary-type-spiritual',
        'sardegnasentieri:warning:4714' => 'warnings-slippery-rock',
        'sardegnasentieri:warning:4715' => 'warnings-water-scarcity',
        'sardegnasentieri:warning:4716' => 'warnings-wild-boar',
        'sardegnasentieri:warning:4717' => 'warnings-overgrown',
        'sardegnasentieri:warning:4718' => 'warnings-ticks',
        'sardegnasentieri:warning:4719' => 'warnings-aggressive-dogs',
        'sardegnasentieri:warning:4720' => 'warnings-fog',
        'sardegnasentieri:warning:4721' => 'warnings-lightning',
        'sardegnasentieri:warning:4722' => 'warnings-flood',
        'sardegnasentieri:warning:4723' => 'warnings-wildfire',
        'sardegnasentieri:warning:4974' => 'warnings-snow',
        'sardegnasentieri:warning:4975' => 'warnings-wind',
        'sardegnasentieri:warning:5021' => 'warnings-road-crossing',
        'sardegnasentieri:tid:4918' => 'soft-mobility-difficulty-hard',
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
        $this->removeRetiredTerms($dry);

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
                $drupalTid = $term[4] ?? null;
                $this->align($identifier, $taxonomyId, [
                    'name' => $name,
                    'single_choice' => false,
                    'sort_order' => $order,
                    'icon' => $icon,
                    'properties' => $drupalTid === null ? null : ['source' => ['drupal_tid' => $drupalTid]],
                ], $dry, $created, $updated);
            }
        }

        $this->renameActivityIdentifiers($dry);
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
     * Toglie i termini ritirati, se nessuno li usa più.
     *
     * Due cose possono trattenerli: un sentiero ancora legato, e un evento di stato che li
     * nomina. Il secondo conta perché le chiavi esterne di `trail_status_events` sono senza
     * cascata: cancellare un termine che compare nella storia fallirebbe con un errore del
     * database invece che con una frase leggibile — ed è voluto che la storia non si possa
     * perdere per sbaglio.
     */
    private function removeRetiredTerms(bool $dry): void
    {
        foreach (self::RETIRED_TERMS as $identifier) {
            $row = TaxonomyTerm::query()->where('identifier', $identifier)->whereNotNull('parent_id')->first();
            if ($row === null) {
                continue;
            }

            $links = DB::table('taxonomy_termables')->where('taxonomy_term_id', $row->id)->count();
            $events = Schema::hasTable('trail_status_events')
                ? DB::table('trail_status_events')
                    ->where('from_term_id', $row->id)
                    ->orWhere('to_term_id', $row->id)
                    ->count()
                : 0;

            if ($links > 0 || $events > 0) {
                $this->warn("Non tolgo il termine {$identifier}: ha {$links} contenuti collegati e {$events} eventi di stato.");

                continue;
            }

            $this->line(($dry ? '[dry-run] ' : '')."Tolgo il termine ritirato {$identifier}");
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
    /**
     * Identifier delle attività cambiati nel tempo: vecchio => nuovo.
     *
     * Le tre righe che dicono il tipo di percorso portavano il nome della sorgente e una parola
     * italiana (`sardegnasentieri:type:sentiero`). Si rinominano, non si ricreano: i layer
     * «Sentieri», «Itinerari» e «Cammini di Sardegna» raccolgono le tracce proprio con queste
     * attività, e una riga nuova accanto alla vecchia li lascerebbe vuoti.
     *
     * @var array<string, string>
     */
    private const RENAMED_ACTIVITIES = [
        'sardegnasentieri:type:sentiero' => 'trail',
        'sardegnasentieri:type:itinerario' => 'itinerary',
        'sardegnasentieri:cammino' => 'walk',
    ];

    /**
     * Rinomina le attività che hanno cambiato identifier, tenendosi le loro relazioni.
     */
    private function renameActivityIdentifiers(bool $dry): void
    {
        foreach (self::RENAMED_ACTIVITIES as $old => $new) {
            $row = TaxonomyActivity::query()->where('identifier', $old)->first();

            if ($row === null) {
                continue;
            }

            if (TaxonomyActivity::query()->where('identifier', $new)->exists()) {
                $this->warn(($dry ? '[dry-run] ' : '')."Esistono sia {$old} sia {$new}: non rinomino.");

                continue;
            }

            $this->line(($dry ? '[dry-run] ' : '')."Rinomino l'attività {$old} -> {$new}");

            if (! $dry) {
                $row->forceFill(['identifier' => $new])->save();
            }
        }
    }

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
                // Sulle EC Track e non sugli EC Poi: sono scale di difficoltà, avvertenze e
                // tipi di percorso, e su un POI non vogliono dire niente. Si accende solo ciò
                // che manca: un ambito scelto dalla scheda dell'app non va riscritto da un
                // allineamento (oc:8910).
                $app->enableTaxonomiesForModel('EcTrack', $missing);
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
