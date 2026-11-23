<?php

declare(strict_types=1);

use App\Http\Controllers\Api\EcPoiDetailController;
use App\Models\EcPoi;
use App\Models\EcTrack;
use App\Services\Import\SardegnaSentieriImportService;
use App\Support\ForestasPrintSheet;
use App\Support\ForestasTrackDetail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Wm\WmPackage\Jobs\Print\BuildOverviewMapJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi as WmEcPoi;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\Print\Contracts\PrintableModel;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\XelatexCompiler;
use Wm\WmPackage\WmPackageServiceProvider;

/**
 * Le schede PDF di Forestas (oc:8982, R16-R20): view generate dal design system SARDEGNA Sentieri
 * sul modello della pagina di dettaglio del sito WordPress, negli override delle view del package
 * (`resources/views/vendor/wm-package/print/`). La scheda del percorso è un foglio da stampare fronte
 * e retro: pagina 1 con i dati, pagina 2 con la sola mappa, A4 al vivo (R20).
 *
 * Il sorgente LaTeX si ottiene passando da `PrintService::generate()` con un compilatore finto che
 * lo cattura: le variabili della view le fornisce il package, non il test, così una chiave
 * rinominata nel package fa fallire questi test invece del Job in produzione. I test che compilano
 * davvero si saltano dove xelatex manca (CI).
 *
 * I casi delle scale vengono dal tema WordPress (`wp-forestas`, branch di oc:9000,
 * `inc/statuses.php` e il registro degli stati del plugin): validazione 0-5, percorribilità 0-4,
 * accessibilità 0-3, con lo stato di assenza a zero.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['filesystems.disks.s3.region' => 'eu-west-1', 'filesystems.disks.wmfe.region' => 'eu-west-1']);
    Storage::fake('wmfe');
    Storage::fake('s3');
    // Mappa dal renderer finto del package: un .env.testing con il driver http farebbe chiamate di rete.
    config(['wm-package.features.print.map_service.driver' => 'fake']);
    App::factory()->createQuietly(['id' => SardegnaSentieriImportService::IMPORT_APP_ID]);

    // Compilatore finto: cattura il sorgente e restituisce un PDF finto; con `$real` compila davvero.
    $this->compiler = new class extends XelatexCompiler
    {
        /** @var list<string> */
        public array $sources = [];

        public bool $real = false;

        public ?string $pdf = null;

        public function compile(string $tex, array $files = []): string
        {
            $this->sources[] = $tex;
            $this->pdf = $this->real ? parent::compile($tex, $files) : '%PDF-1.4 finto';

            return $this->pdf;
        }
    };
    app()->instance(XelatexCompiler::class, $this->compiler);
});

function printOverrideTrack(array $attributes = [], array $properties = []): EcTrack
{
    return EcTrack::withoutEvents(fn () => EcTrack::query()->create([
        'name' => ['it' => 'Monte Corrasi'],
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => array_merge(['ref' => 'B 401'], $properties),
        'geometry' => DB::raw("ST_GeomFromText('MULTILINESTRING Z((9.40 40.25 0, 9.41 40.26 0))', 4326)"),
        ...$attributes,
    ]));
}

function printOverridePoi(array $properties = [], array $name = ['it' => 'Nuraghe Serbissi']): EcPoi
{
    return EcPoi::withoutEvents(fn () => EcPoi::query()->create([
        'name' => $name,
        'app_id' => SardegnaSentieriImportService::IMPORT_APP_ID,
        'properties' => $properties,
        'geometry' => DB::raw("ST_GeomFromText('POINT Z(9.05 40.499 0)', 4326)"),
    ]));
}

/**
 * Aggancia al sentiero il termine `identifier` della tassonomia `taxonomy`, creandoli se mancano. Il
 * nome italiano diventa `$name` anche se il termine esiste già (le migrazioni del package creano
 * quelli della percorribilità).
 */
function printOverrideTerm(EcTrack $track, string $taxonomy, string $identifier, string $name): void
{
    $parent = TaxonomyTerm::query()->firstOrCreate(['identifier' => $taxonomy, 'parent_id' => null], ['name' => ['it' => $taxonomy]]);
    $term = TaxonomyTerm::query()->firstOrCreate(['identifier' => $identifier], ['parent_id' => $parent->id, 'name' => ['it' => $name]]);
    $term->setTranslation('name', 'it', $name)->saveQuietly();
    $track->taxonomyTerms()->attach($term->id);
    $track->unsetRelation('taxonomyTerms');
}

/** Il sorgente LaTeX che `PrintService::generate()` passa al compilatore. */
function printOverrideRender(Model&PrintableModel $model): string
{
    app(PrintService::class)->generate($model, 'it');

    return printOverrideWithoutBlade((string) last(test()->compiler->sources));
}

/**
 * Una direttiva Blade attaccata a una lettera (`\endgroup@if`) non si compila e finirebbe nel
 * LaTeX: nessun sorgente renderizzato deve contenerne.
 */
function printOverrideWithoutBlade(string $tex): string
{
    expect($tex)->not->toMatch('/@(if|else|elseif|endif|foreach|endforeach|php|endphp)\b/');

    return $tex;
}

/**
 * Le variabili che il package passa alla view, catturate durante `generate()`, con alcune
 * sostituite. Le chiavi sostituite devono esistere fra quelle del package.
 *
 * @param  array<string,mixed>  $overrides
 */
function printOverrideRenderWith(Model&PrintableModel $model, array $overrides): string
{
    $captured = null;
    View::composer($model->printView(), function ($view) use (&$captured) {
        $captured ??= $view->getData();
    });
    app(PrintService::class)->generate($model, 'it');

    expect($captured)->toBeArray()
        ->and(array_diff(array_keys($overrides), array_keys($captured)))->toBe([]);

    return printOverrideWithoutBlade(view($model->printView(), array_merge($captured, $overrides))->render());
}

/** Il corpo del documento, senza il preambolo (che definisce le macro). */
function printOverrideBody(string $tex): string
{
    return (string) strstr($tex, '\\begin{document}');
}

/** Posizione di `$needle` nel sorgente; fallisce se manca. */
function printOverridePos(string $tex, string $needle): int
{
    $pos = strpos($tex, $needle);
    expect($pos)->not->toBeFalse("«{$needle}» non è nel sorgente");

    return (int) $pos;
}

/** Il logotipo SARDEGNA con le tinte per lettera di `LogotipoSardegna`. */
const PRINT_OVERRIDE_LOGO = '\textcolor{sar-terra}{S}\textcolor{sar-verde}{A}\textcolor{sar-blu}{R}\textcolor{sar-terra}{D}'
    .'\textcolor{sar-oro}{E}\textcolor{sar-blu}{G}\textcolor{sar-terra}{N}\textcolor{sar-oro}{A}';

describe('design system nel preambolo', function () {
    it('non carica più lo stile generato dai token, solo wm-print del package', function () {
        $tex = printOverrideRender(printOverrideTrack());

        expect($tex)->not->toContain('\usepackage{forestas}')
            ->and($tex)->toContain('\usepackage{wm-print}')
            ->and(config('wm-package.features.print.style_path'))->toBeNull()
            ->and(is_file(resource_path('print/forestas.sty')))->toBeFalse()
            ->and(is_file(resource_path('print/design-system/tokens.json')))->toBeFalse();
    });

    it('definisce i colori del design system con i valori esatti del tokens.json', function () {
        foreach ([printOverrideTrack(), printOverridePoi()] as $model) {
            expect(printOverrideRender($model))
                ->toContain('\definecolor{sar-blu}{HTML}{254877}')
                ->toContain('\definecolor{sar-verde}{HTML}{566D27}')
                ->toContain('\definecolor{sar-terra}{HTML}{CA5C30}')
                ->toContain('\definecolor{sar-oro}{HTML}{E9A33F}')
                ->toContain('\definecolor{st-4}{HTML}{4B6B22}')
                ->toContain('\definecolor{st-3}{HTML}{8A5A00}')
                ->toContain('\definecolor{st-2}{HTML}{7A6A55}')
                ->toContain('\definecolor{st-1}{HTML}{9B1B1B}')
                ->toContain('\definecolor{tipo-sentiero}{HTML}{DE1B0D}')
                ->toContain('\definecolor{tipo-da-vedere}{HTML}{B7117F}')
                ->toContain('\definecolor{ink}{HTML}{1A1A1A}')
                ->toContain('\definecolor{ink-3}{HTML}{767676}')
                ->toContain('\definecolor{line}{HTML}{DEDAD2}')
                ->toContain('\definecolor{surf-2}{HTML}{F7F5F1}')
                ->toContain('{Work Sans}');
        }
    });

    it('compone il logotipo in testo con le tinte per lettera e la coda Sentieri', function () {
        foreach ([printOverrideTrack(), printOverridePoi()] as $model) {
            $tex = printOverrideRender($model);

            expect($tex)->toContain(PRINT_OVERRIDE_LOGO)
                // La coda in ink-3, peso 300 (Work Sans Light), 0,62 del corpo del logotipo.
                ->toMatch('/\\\\ForestasLogoLight[^\n]*\\\\color\{ink-3\}Sentieri/')
                ->toContain('Work Sans Light')
                ->toContain('Work Sans Bold')
                // Nessuna immagine nel corpo oltre alla mappa (segnaposto del renderer fake, inclusa da
                // \ForestasMappaPagina): il logotipo non è un'immagine.
                ->and(printOverrideBody($tex))->not->toContain('\includegraphics')
                ->toContain('\ForestasMappaPagina{map.png}');
        }
    });

    it('non usa le tinte solo grafiche come colore del testo', function () {
        foreach ([printOverrideTrack(), printOverridePoi()] as $model) {
            $tex = printOverrideRender($model);
            // sar-terra e sar-oro solo nelle lettere del logotipo e nei filetti (\rule): tolta la riga
            // delle lettere, non restano né \textcolor, né text= dei nodi TikZ, né \color senza \rule.
            $logo = '\\ForestasLogoBold\\fontsize';
            expect(substr_count($tex, $logo))->toBe(1);
            $rest = (string) preg_replace('/^.*'.preg_quote($logo, '/').'.*$/m', '', $tex);

            expect($rest)->not->toMatch('/\\\\textcolor\{sar-(terra|oro)\}/')
                ->and($rest)->not->toMatch('/text=sar-(terra|oro)\b/')
                ->and($rest)->not->toMatch('/\\\\color\{sar-(terra|oro)\}(?!\\\\rule)/');
        }
    });
});

describe('la mappa: una pagina a sé, A4 al vivo', function () {
    it('chiede al servizio mappe la misura della pagina A4 e la stende sulla pagina intera', function () {
        // Il servizio mappe disegna già a misura di A4 (call del 23/11): niente margini né testata.
        expect(config('wm-package.features.print.map.width_mm'))->toBe(210)
            ->and(config('wm-package.features.print.map.height_mm'))->toBe(297);

        foreach ([printOverrideTrack(), printOverridePoi()] as $model) {
            $tex = printOverrideBody(printOverrideRender($model));
            expect($tex)->toContain('\ForestasMappaPagina{map.png}{');
            expect($tex)->not->toContain('\ForestasMappa{');
        }
    });

    it('non riserva più spazio al blocco di stato sotto la mappa', function () {
        $tex = printOverrideRender(printOverrideTrack());

        expect($tex)->not->toContain('\ForestasBloccoStato');
        expect($tex)->not->toContain('\ForestasStampaBloccoStato');
    });

    it('mette la mappa per ultima, da sola: dopo non c\'è altro', function () {
        foreach ([printOverrideTrack(), printOverridePoi()] as $model) {
            $tex = printOverrideBody(printOverrideRender($model));

            expect(substr($tex, printOverridePos($tex, '\ForestasMappaPagina{')))
                ->toMatch('/^\\\\ForestasMappaPagina\{map\.png\}\{[^\n]*\}\n\\\\end\{document\}\s*$/');
        }
    });

    it('senza mappa la scheda del percorso è una pagina sola', function () {
        $tex = printOverrideBody(printOverrideRenderWith(printOverrideTrack(), ['mapFile' => null]));

        expect($tex)->not->toContain('\ForestasMappaPagina')
            ->and($tex)->not->toContain('\newpage')
            ->and($tex)->not->toContain('\clearpage');
    });
});

describe('scheda del sentiero', function () {
    it('segue l\'ordine del foglio: in pagina 1 testata, stato, in breve, dati e rimando; poi la mappa', function () {
        $track = printOverrideTrack(
            ['forestas_registry_code' => 'Z-NU-B-401', 'forestas_inclusivity_physical' => 40, 'forestas_inclusivity_aids' => 30, 'forestas_inclusivity_digital' => 20],
            ['not_accessible' => true, 'not_accessible_message' => ['it' => 'Sentiero chiuso per frana'], 'forestas' => ['url' => 'https://www.example.test/sentieri/monte-corrasi']],
        );
        printOverrideTerm($track, 'hiking-difficulty', 'hiking-difficulty-e', 'E');

        $tex = printOverrideBody(printOverrideRender($track));
        $order = [
            '\begin{ForestasPaginaUnica}',
            '\ForestasLogotipo',
            '\TitoloScheda{Monte Corrasi}',
            'Sentiero chiuso per frana',
            '\ForestasFasciaPercorribilita',
            '\ForestasIndicatore{'.__('Trail register validation', [], 'it').'}',
            '\ForestasIndicatore{'.__('Walkability', [], 'it').'}',
            '\ForestasIndicatore{'.__('Accessibility', [], 'it').'}',
            'Sentiero per tutti',
            '\TitoloSezione{Il sentiero in breve}',
            '\ForestasOcchiello{Dati catastali e altimetrici}',
            '\ForestasDato{Codice catastale}{Z-NU-B-401}',
            '\ForestasIndirizzo',
            '\ForestasQr',
            '\end{ForestasPaginaUnica}',
            '\ForestasMappaPagina{map.png}',
        ];
        $positions = array_map(fn ($needle) => printOverridePos($tex, $needle), $order);
        $sorted = $positions;
        sort($sorted);

        expect(array_combine($order, $positions))->toBe(array_combine($order, $sorted))
            // Fino alla mappa nessun salto di pagina: la pagina 1 è una sola.
            ->and(substr($tex, 0, printOverridePos($tex, '\ForestasMappaPagina')))->not->toContain('\newpage');
    });

    it('è la versione breve: niente descrizione, informazioni utili, roadbook, attività e avvertenze né galleria', function () {
        $track = printOverrideTrack([], [
            'config_detail' => [['box_type' => 'info', 'items' => [['title' => ['it' => 'Tappa 1'], 'content' => ['it' => '<p>Si sale.</p>']]]]],
            'description' => ['it' => '<p>Il sentiero sale.</p>'],
            'forestas' => ['info_utili' => ['it' => ['<p>Acqua alla fonte.</p>']]],
        ]);
        printOverrideTerm($track, 'hiking-difficulty', 'hiking-difficulty-e', 'E');
        printOverrideTerm($track, 'warnings', 'sardegnasentieri:warning:1', 'Esposto al sole');

        $tex = printOverrideBody(printOverrideRenderWith($track, ['photos' => ['photo-1.jpg']]));

        foreach (['Il sentiero sale.', 'Si sale.', 'Tappa 1', 'Acqua alla fonte.', 'Esposto al sole', 'photo-1.jpg',
            'Descrizione', 'Informazioni utili', 'roadbook', 'Attività e avvertenze', 'Galleria fotografica'] as $absent) {
            expect($tex)->not->toContain($absent);
        }
    });

    it('dispone lo stato su due righe: validazione e percorribilità affiancate, accessibilità a tutta larghezza', function () {
        $track = printOverrideTrack();
        printOverrideTerm($track, 'inclusivity-difficulty', 'inclusivity-difficulty-ae', 'AE');
        printOverrideTerm($track, 'inclusivity-disabilities', 'inclusivity-disabilities-physical', 'Motoria');

        $tex = printOverrideBody(printOverrideRender($track));
        $validation = printOverridePos($tex, '\ForestasIndicatore{'.__('Trail register validation', [], 'it').'}');
        $walkability = printOverridePos($tex, '\ForestasIndicatore{'.__('Walkability', [], 'it').'}');
        $row = printOverridePos($tex, '\multicolumn{2}');
        $accessibility = printOverridePos($tex, '\ForestasIndicatore{'.__('Accessibility', [], 'it').'}');
        $icons = printOverridePos($tex, '\ForestasDisabilita{1}');

        // Prima riga: due colonne; seconda: una cella sulle due colonne con scala a sinistra e icone a destra.
        expect($validation)->toBeLessThan($walkability)
            ->and($walkability)->toBeLessThan($row)
            ->and($row)->toBeLessThan($accessibility)
            ->and($accessibility)->toBeLessThan($icons)
            ->and(substr_count($tex, '\ForestasDisabilita{'))->toBe(3)
            ->and(substr($tex, $row, $icons - $row))->toContain('\raggedleft');
    });

    it('mette l\'avviso di percorribilità sotto la testata, in apertura del blocco di stato', function () {
        $on = printOverrideTrack([], ['not_accessible' => true, 'not_accessible_message' => ['it' => 'Sentiero chiuso per frana']]);
        $tex = printOverrideBody(printOverrideRender($on));

        expect(printOverridePos($tex, 'Sentiero chiuso per frana'))
            ->toBeGreaterThan(printOverridePos($tex, '\FilettoOro'))
            ->toBeLessThan(printOverridePos($tex, '\ForestasFasciaPercorribilita'));
    });

    it('non ha le sezioni dei punti di interesse e dei percorsi correlati', function () {
        $track = printOverrideTrack();
        $track->ecPois()->attach(printOverridePoi([], ['it' => 'Fontana di Girgini'])->id, ['order' => 1]);
        $track->relatedTracks()->attach(printOverrideTrack(['name' => ['it' => 'Vicino']], ['ref' => 'B 402'])->id);

        $tex = printOverrideRender($track);
        $poiTex = printOverrideRender(printOverridePoi());

        foreach (['Punti di interesse correlati', 'Percorsi correlati', 'Fontana di Girgini', 'Vicino'] as $absent) {
            expect($tex)->not->toContain($absent);
        }
        foreach (['Punti di interesse correlati', 'Percorsi vicini', 'Percorsi correlati', '\ForestasVoce'] as $absent) {
            expect($poiTex)->not->toContain($absent);
        }

        $sheet = ForestasPrintSheet::track($track, ForestasTrackDetail::build($track), 'it');
        expect($sheet)->not->toHaveKey('nearbyTracks');
    });

    it('mostra la scala della validazione catastale come il sito', function (?string $identifier, string $scale, string $value) {
        $track = printOverrideTrack();
        if ($identifier !== null) {
            printOverrideTerm($track, 'validation-status', $identifier, 'Nome del termine');
        }

        expect(printOverrideRender($track))->toContain($scale)->toContain('\ForestasValore{'.$value.'}');
    })->with([
        'senza stato' => [null, '\ForestasScala{ink-3}{0}{5}', '0 su 5 · Non verificato'],
        'non verificato' => ['validation-status-unverified', '\ForestasScala{ink-3}{0}{5}', '0 su 5 · Non verificato'],
        'in revisione' => ['validation-status-under-review', '\ForestasScala{st-2}{2}{5}', '2 su 5 · In revisione-validazione'],
        'pre-accatastamento' => ['validation-status-pre-registration', '\ForestasScala{st-3}{3}{5}', '3 su 5 · In pre-accatastamento o manutenzione'],
        'validato' => ['validation-status-validated', '\ForestasScala{st-4}{4}{5}', '4 su 5 · Validato'],
        'certificato' => ['validation-status-certified', '\ForestasScala{st-4}{5}{5}', '5 su 5 · Certificato'],
    ]);

    it('usa nell\'occhiello della testata il nome della scheda coerente con il tipo', function (?string $routeType, string $label) {
        $track = printOverrideTrack();
        if ($routeType !== null) {
            printOverrideTerm($track, 'route-type', $routeType, 'Tipo');
        }

        expect(printOverrideRender($track))->toContain('\ForestasOcchiello{'.$label.'}');
    })->with([
        'sentiero (senza tipo)' => [null, 'Scheda del sentiero'],
        'sentiero' => ['route-type-trail', 'Scheda del sentiero'],
        'itinerario' => ['route-type-itinerary', 'Scheda dell\'itinerario'],
        'cammino' => ['route-type-walk', 'Scheda del cammino'],
    ]);

    it('non fallisce con dati di tipo inatteso', function () {
        // `ref` non scalare: lo regge ForestasPrintSheet, ma non PrintService del package, che lo
        // converte in stringa per la richiesta della mappa («Array to string conversion»): si prova
        // la parte di Forestas da sola.
        $odd = printOverrideTrack([], ['ref' => ['B 401']]);
        $sheet = ForestasPrintSheet::track($odd, ForestasTrackDetail::build($odd), 'it');
        expect($sheet['ref'])->toBeNull()->and($sheet['name'])->toBe('Monte Corrasi');

        $track = printOverrideTrack([], [
            'config_detail' => 'non un elenco',
            'taxonomy_where' => 'Oliena',
            'forestas' => ['inclusivity' => 'A1', 'info_utili' => 42],
            'from' => ['it' => ['annidato']],
            'roundtrip' => 'forse',
        ]);

        $tex = printOverrideRender($track);

        expect($tex)->toContain('\TitoloScheda{Monte Corrasi}')
            ->toContain('\end{document}')
            ->toContain('\ForestasDato{Partenza}{annidato}');
    });

    it('mette lo stato di validazione nel badge della testata, con numero ed etichetta', function () {
        $track = printOverrideTrack();
        printOverrideTerm($track, 'validation-status', 'validation-status-pre-registration', 'x');

        expect(printOverrideRender($track))->toContain('\ForestasBadgeStato{st-3}{3 · In pre-accatastamento o manutenzione}');
    });

    it('mostra la scala della percorribilità come il sito', function (?string $suffix, string $scale, string $value) {
        $track = printOverrideTrack();
        if ($suffix !== null) {
            printOverrideTerm($track, 'walkability-status', 'walkability-status-'.$suffix, 'Stato '.$suffix);
        }

        expect(printOverrideRender($track))->toContain($scale)->toContain('\ForestasValore{'.$value.'}');
    })->with([
        // Senza stato, come sul sito: solo «Dato non ancora disponibile», senza «0 su 4».
        'senza stato' => [null, '\ForestasScala{ink-3}{0}{4}', 'Dato non ancora disponibile'],
        'sconosciuta' => ['unknown', '\ForestasScala{ink-3}{0}{4}', '0 su 4 · Stato unknown'],
        'non percorribile' => ['not-walkable', '\ForestasScala{st-1}{2}{4}', '2 su 4 · Stato not-walkable'],
        'parzialmente' => ['partially-walkable', '\ForestasScala{st-3}{3}{4}', '3 su 4 · Stato partially-walkable'],
        'percorribile' => ['walkable', '\ForestasScala{st-4}{4}{4}', '4 su 4 · Stato walkable'],
    ]);

    it('scrive nella fascia della percorribilità lo stato o «Dato non ancora disponibile»', function () {
        $without = printOverrideRender(printOverrideTrack());
        $track = printOverrideTrack();
        printOverrideTerm($track, 'walkability-status', 'walkability-status-walkable', 'Percorribile');
        $with = printOverrideRender($track);

        expect($without)->toMatch('/\\\\ForestasFasciaPercorribilita\{ink-3\}\{[^\n]*Dato non ancora disponibile/')
            ->and($with)->toMatch('/\\\\ForestasFasciaPercorribilita\{st-4\}\{[^\n]*Percorribile/');
    });

    it('mostra la scala dell\'accessibilità e le tre tipologie di disabilità come il sito', function () {
        $none = printOverrideRender(printOverrideTrack());
        $track = printOverrideTrack();
        printOverrideTerm($track, 'inclusivity-difficulty', 'inclusivity-difficulty-ae', 'AE');
        printOverrideTerm($track, 'inclusivity-disabilities', 'inclusivity-disabilities-physical', 'Motoria');
        $ae = printOverrideRender($track);

        expect($none)->not->toContain('\ForestasDisabilita{1}')
            ->and($none)->toContain('\ForestasScala{ink-3}{0}{3}')
            ->toContain('\ForestasValore{Non classificato}')
            ->toContain('\ForestasDisabilita{0}')
            ->and($ae)->toContain('\ForestasScala{sar-blu}{2}{3}')
            ->toContain('\ForestasValore{AE · Accessibile Escursionistico}')
            ->toMatch('/\\\\ForestasDisabilita\{1\}\{[^\n]*\}\{Motoria\}/')
            ->toMatch('/\\\\ForestasDisabilita\{0\}\{[^\n]*\}\{Sensoriale\}/')
            ->toMatch('/\\\\ForestasDisabilita\{0\}\{[^\n]*\}\{Psichica cognitiva\}/')
            ->toContain('AT')->toContain('AEE');
    });

    it('disegna le icone delle disabilità dai tracciati SVG del tema, senza file esterni', function () {
        $tex = printOverrideRender(printOverrideTrack());

        // Il tracciato della sedia a rotelle del tema WordPress (wp-package/track.php).
        expect($tex)->toContain('svg {M11 7v6h5l2.5 5.5M11 10h4.5}')
            ->toContain('\usetikzlibrary{svg.path}');
    });

    it('mostra «Il sentiero in breve» con i quattro dati del sito e il trattino se mancano', function () {
        $empty = printOverrideRender(printOverrideTrack());
        $full = printOverrideRender(printOverrideTrack([], [
            'distance' => 6.6, 'ascent' => 1008, 'duration_forward' => 285, 'ele_min' => 435, 'ele_max' => 1432,
        ]));

        foreach (['Lunghezza', 'Dislivello totale', 'Tempo di percorrenza', 'Quota min — max'] as $label) {
            expect($empty)->toContain('\ForestasFatto{—}{'.$label.'}');
        }
        expect($full)->toContain('\ForestasFatto{6,6 km}{Lunghezza}')
            ->toContain('\ForestasFatto{1.008 m}{Dislivello totale}')
            ->toContain('\ForestasFatto{4 h 45 min}{Tempo di percorrenza}')
            ->toContain('\ForestasFatto{435 — 1432}{Quota min — max}');
    });

    it('mette nei dati brevi solo i dati catastali che non sono già sopra, senza righe vuote', function () {
        $track = printOverrideTrack(
            ['forestas_registry_code' => 'Z-NU-B-401', 'forestas_survey_date' => '2025-06-04'],
            ['from' => ['it' => 'Oliena'], 'roundtrip' => false, 'distance' => 6.6],
        );
        printOverrideTerm($track, 'hiking-difficulty', 'hiking-difficulty-e', 'E');
        printOverrideTerm($track, 'validation-status', 'validation-status-validated', 'Validato');
        $tex = printOverrideBody(printOverrideRender($track));

        expect($tex)->toContain('\ForestasDato{Partenza}{Oliena}')
            ->toContain('\ForestasDato{Andata e ritorno}{No}')
            ->toContain('\ForestasDato{'.__('Difficulty level', [], 'it').'}{Escursionismo E}')
            ->toContain('\ForestasDato{Codice catastale}{Z-NU-B-401}')
            ->toContain('\ForestasDato{Data del rilievo}{04/06/2025}');
        // Arrivo manca: la riga non esce. Misure e stato di validazione sono già in «In breve» e nel
        // blocco di stato; le tabelle complete dei gruppi non ci sono più.
        foreach (['\ForestasDato{Arrivo}', '\ForestasDato{Lunghezza}', '\ForestasDato{Stato di validazione}', '\ForestasGruppo', '\RigaTabella{'] as $absent) {
            expect($tex)->not->toContain($absent);
        }

        // Gli itinerari non hanno i dati del catasto, come sul sito.
        $itinerary = printOverrideTrack(['forestas_registry_code' => 'Z-NU-B-402'], ['from' => ['it' => 'Oliena']]);
        printOverrideTerm($itinerary, 'route-type', 'route-type-itinerary', 'Itinerario');
        $itineraryTex = printOverrideBody(printOverrideRender($itinerary));
        expect($itineraryTex)->toContain('\ForestasDato{Partenza}{Oliena}');
        expect($itineraryTex)->not->toContain('\ForestasDato{Codice catastale}');
    });

    it('rimanda alla pagina del sito dei dati (properties.forestas.url) con il QR code e l\'indirizzo', function () {
        $track = printOverrideTrack([], ['forestas' => ['url' => 'https://www.example.test/sentieri/salita-sul-monte-corrasi-b_401?x=1&y=100%']]);
        $poi = printOverridePoi(['forestas' => ['url' => 'https://www.example.test/da-vedere/san-mauro']]);

        expect(printOverrideBody(printOverrideRender($track)))
            // Nel QR i caratteri speciali di LaTeX escaped come vuole il pacchetto qrcode.
            ->toContain('\ForestasQr{https://www.example.test/sentieri/salita-sul-monte-corrasi-b\_401?x=1\&y=100\%}')
            // Nell'indirizzo leggibile senza schema.
            ->toContain('{www.example.test/sentieri/salita-sul-monte-corrasi-b\_401?x=1\&y=100\%}')
            ->and(printOverrideBody(printOverrideRender($poi)))->toContain('\ForestasQr{https://www.example.test/da-vedere/san-mauro}');

        // Senza indirizzo (creato in Nova) o con un indirizzo che non è http(s): niente rimando.
        foreach ([printOverrideTrack(), printOverridePoi(), printOverrideTrack([], ['forestas' => ['url' => 'javascript:alert(1)']])] as $model) {
            $tex = printOverrideBody(printOverrideRender($model));
            expect($tex)->not->toContain('\ForestasQr');
            expect($tex)->not->toContain('\ForestasIndirizzo');
        }
    });

    it('non mostra la mappa senza il file della mappa', function () {
        expect(printOverrideBody(printOverrideRenderWith(printOverrideTrack(), ['mapFile' => null])))->not->toContain('\ForestasMappa');
    });

    it('mostra «Sentiero per tutti» solo se c\'è il punteggio', function () {
        $partial = printOverrideRender(printOverrideTrack(['forestas_inclusivity_physical' => 40]));
        $full = printOverrideRender(printOverrideTrack([
            'forestas_inclusivity_physical' => 40,
            'forestas_inclusivity_aids' => 30,
            'forestas_inclusivity_digital' => 20,
        ]));

        expect($partial)->not->toContain('Sentiero per tutti')
            ->and($full)->toContain('Sentiero per tutti')
            ->toContain(__('Inclusivity score', [], 'it').': 90')
            ->toContain('\bfseries A1');
    });

    it('mostra l\'avviso solo se è pubblicato, nel colore dello stato di percorribilità', function () {
        $off = printOverrideRender(printOverrideTrack([], ['not_accessible' => false, 'not_accessible_message' => ['it' => 'Testo nascosto']]));
        $on = printOverrideTrack([], [
            'not_accessible' => true,
            'not_accessible_message' => ['it' => 'Sentiero chiuso per frana'],
            'not_accessible_updated_at' => '2026-11-17T10:50:02+00:00',
        ]);
        $neutral = printOverrideRender($on);
        printOverrideTerm($on, 'walkability-status', 'walkability-status-not-walkable', 'Non percorribile');

        expect($off)->not->toContain('Testo nascosto')
            ->and($neutral)->toContain('Sentiero chiuso per frana')
            ->toContain(__('Notice', [], 'it'))
            ->toContain('17/11/2026')
            ->toContain('\ForestasRiquadro{ink-3}')
            ->and(printOverrideRender($on))->toContain('\ForestasRiquadro{st-1}');
    });

    it('fa l\'escape dei testi che vengono dai dati di Forestas', function () {
        $evil = 'X \input{x} 100% #1 & {y}';
        $escaped = 'X \textbackslash{}input\{x\} 100\% \#1 \& \{y\}';
        $track = printOverrideTrack(
            ['forestas_registry_code' => 'Z_NU#1'],
            [
                'not_accessible' => true,
                'not_accessible_message' => ['it' => 'Avviso '.$evil],
                'from' => ['it' => 'Da '.$evil],
                'to' => ['it' => 'A '.$evil],
                'taxonomy_where' => ['R1' => ['it' => 'Comune '.$evil, '_admin_level' => 8]],
            ],
        );
        printOverrideTerm($track, 'walkability-status', 'walkability-status-walkable', 'Stato '.$evil);

        $tex = printOverrideRender($track);

        expect($tex)->not->toContain('\input{')
            ->toContain('Z\_NU\#1')
            ->toContain('Avviso '.$escaped)
            ->toContain('Da '.$escaped)
            ->toContain('A '.$escaped)
            ->toContain('Comune '.$escaped)
            ->toContain('Stato '.$escaped);
    });
});

describe('scheda del punto di interesse', function () {
    it('sta in un foglio: pagina 1 con testata, «Il punto in breve», rimando e foto; pagina 2 con la mappa', function () {
        $poi = printOverridePoi([
            'taxonomy_where' => [
                'R40182' => ['en' => 'Sorgono', '_admin_level' => 8],
                '81' => ['it' => 'Area T (Centrale)'],
            ],
            'description' => ['it' => '<p>Un santuario.</p>'],
            'forestas' => ['url' => 'https://www.example.test/da-vedere/san-mauro', 'come_arrivare' => ['it' => 'Dalla statale 128.']],
        ]);

        $full = printOverrideRenderWith($poi, ['photos' => ['photo-1.jpg']]);
        $tex = printOverrideBody($full);

        $positions = array_map(fn ($needle) => printOverridePos($tex, $needle), [
            '\begin{ForestasPaginaUnica}',
            '\ForestasLogotipo{',
            '\TitoloScheda{Nuraghe Serbissi}',
            '\TitoloSezione{Il punto in breve}',
            '\ForestasIndirizzo',
            '\ForestasQr',
            '\TitoloSezione{Galleria fotografica}',
            '\end{ForestasPaginaUnica}',
            '\ForestasMappaPagina{map.png}',
        ]);
        $sorted = $positions;
        sort($sorted);

        expect($positions)->toBe($sorted)
            ->and($tex)->toContain('\RigaTabella{Comune}{Sorgono}')
            ->toContain('\RigaTabella{Area geografica}{Area T (Centrale)}')
            ->toContain('\RigaTabella{Tipologia}{—}')
            ->and($full)->toContain('\colorlet{forestas-tipo}{tipo-da-vedere}');
        // Niente descrizione né «come arrivare».
        foreach (['Un santuario.', 'Dalla statale', 'Il punto di interesse}', 'Come arrivare'] as $absent) {
            expect($tex)->not->toContain($absent);
        }
    });

    it('mette al massimo sei foto, due righe da tre', function () {
        $photos = array_map(fn ($n) => "photo-{$n}.jpg", range(1, 8));
        $tex = printOverrideBody(printOverrideRenderWith(printOverridePoi(), ['photos' => $photos]));

        expect($tex)->toContain('{photo-6.jpg}')
            ->and(substr_count($tex, '\includegraphics'))->toBe(6);
        expect($tex)->not->toContain('{photo-7.jpg}');
    });

    it('usa la view di Forestas anche con il modello EcPoi del package', function () {
        $poi = WmEcPoi::query()->findOrFail(printOverridePoi()->id);

        expect(printOverrideRender($poi))->toContain(PRINT_OVERRIDE_LOGO);
    });
});

it('non fallisce con tutte le variabili opzionali vuote', function () {
    $empty = [
        'name' => '',
        'descriptionLatex' => '',
        'stats' => [],
        'relatedPois' => null,
        'mapFile' => null,
        'photos' => [],
    ];

    $track = printOverrideRenderWith(printOverrideTrack(), $empty);
    $poi = printOverrideRenderWith(printOverridePoi(), array_diff_key($empty, ['relatedPois' => true]));

    foreach ([$track, $poi] as $tex) {
        expect($tex)->toContain('\begin{document}')->toContain('\end{document}')
            ->and(printOverrideBody($tex))->not->toContain('\includegraphics');
    }
});

it('mette le foto del POI a tre per riga, senza spazi fra una foto e l\'altra', function () {
    $photos = ['photo-1.jpg', 'photo-2.jpg', 'photo-3.jpg', 'photo-4.jpg'];

    foreach ([printOverridePoi()] as $model) {
        $tex = printOverrideBody(printOverrideRenderWith($model, ['photos' => $photos]));
        $start = strpos($tex, '\makebox');
        $end = strrpos($tex, '{photo-4.jpg}}');
        expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

        // Un fine riga in LaTeX vale come spazio, se non è commentato con `%`: il blocco delle foto,
        // tolti i `%` di fine riga, non deve avere spazi dopo `\hspace{...}` (la terza foto andrebbe a capo).
        $block = str_replace("%\n", '', substr($tex, $start, $end - $start));
        expect($block)->not->toMatch('/\\\\hspace\{0\.02\\\\linewidth\}\s/')
            ->and(substr_count($block, '\hspace{0.02\linewidth}'))->toBe(2)
            ->and(substr_count($block, '\par\vspace{6pt}'))->toBe(1);
    }
});

it('compila la scheda della traccia e del POI con il preambolo vero', function () {
    $this->compiler->real = true;
    // Con il rimando al sito, per compilare anche il QR code (pacchetto qrcode).
    $track = printOverrideTrack([
        'forestas_registry_code' => 'Z-NU-B-401',
        'forestas_inclusivity_physical' => 40,
        'forestas_inclusivity_aids' => 30,
        'forestas_inclusivity_digital' => 20,
    ], [
        'not_accessible' => true,
        'not_accessible_message' => ['it' => 'Frana & 100% #1'],
        'distance' => 6.6, 'ascent' => 1008, 'duration_forward' => 285, 'ele_min' => 435, 'ele_max' => 1432,
        'config_detail' => [['box_type' => 'info', 'items' => [['title' => ['it' => 'Tappa 1'], 'content' => ['it' => '<p>Si sale.</p>']]]]],
        'forestas' => ['url' => 'https://www.example.test/sentieri/monte-corrasi'],
    ]);
    printOverrideTerm($track, 'walkability-status', 'walkability-status-walkable', 'Percorribile');
    printOverrideTerm($track, 'validation-status', 'validation-status-pre-registration', 'In pre-accatastamento');
    printOverrideTerm($track, 'inclusivity-difficulty', 'inclusivity-difficulty-ae', 'AE');
    printOverrideTerm($track, 'inclusivity-disabilities', 'inclusivity-disabilities-physical', 'Motoria');
    printOverrideTerm($track, 'hiking-difficulty', 'hiking-difficulty-e', 'E');

    foreach ([$track, printOverridePoi()] as $model) {
        app(PrintService::class)->generate($model, 'it');

        expect(strlen((string) $this->compiler->pdf))->toBeGreaterThan(1000)
            ->and(str_starts_with((string) $this->compiler->pdf, '%PDF'))->toBeTrue();
    }
})->skip(! is_executable('/usr/bin/xelatex'), 'xelatex non installato');

it('compila un foglio di due facciate: i dati in pagina 1, anche quando sono tanti, e la sola mappa in pagina 2', function () {
    $this->compiler->real = true;
    // Il caso più pieno: avviso, «Sentiero per tutti», nome e località lunghi, tutti i dati brevi.
    $track = printOverrideTrack([
        'name' => ['it' => 'Sca Martini, bivio 324 - Ollastus (D 334) e ritorno per la valle del rio'],
        'forestas_registry_code' => 'Z-NU-B-401', 'forestas_survey_date' => '2025-06-04',
        'forestas_inclusivity_physical' => 40, 'forestas_inclusivity_aids' => 30, 'forestas_inclusivity_digital' => 20,
    ], [
        'not_accessible' => true,
        'not_accessible_message' => ['it' => 'Sentiero chiuso per frana fra il bivio e la sorgente, deviazione segnalata.'],
        'distance' => 6.6, 'ascent' => 1008, 'duration_forward' => 285, 'ele_min' => 435, 'ele_max' => 1432,
        'from' => ['it' => 'Oliena (Su Carmene)'], 'to' => ['it' => 'Corrasi - Punto di partenza B-402'], 'roundtrip' => false,
        'description' => ['it' => '<p>Il sentiero sale.</p>'],
        'forestas' => ['url' => 'https://www.example.test/sentieri/sca-martini-bivio-324-ollastus-d-334'],
        'taxonomy_where' => [
            'R1' => ['it' => 'Villasimius', '_admin_level' => 8], 'R2' => ['it' => 'Villaputzu', '_admin_level' => 8],
            'R3' => ['it' => 'Arzana', '_admin_level' => 8], 'R4' => ['it' => 'Lanusei', '_admin_level' => 8],
            'R5' => ['it' => 'Loceri', '_admin_level' => 8], 'R6' => ['it' => 'Tertenia', '_admin_level' => 8],
            'R7' => ['it' => 'Gairo', '_admin_level' => 8], 'R8' => ['it' => 'Cardedu', '_admin_level' => 8],
        ],
    ]);
    printOverrideTerm($track, 'validation-status', 'validation-status-validated', 'Validato');
    printOverrideTerm($track, 'walkability-status', 'walkability-status-walkable', 'Percorribile');
    printOverrideTerm($track, 'inclusivity-difficulty', 'inclusivity-difficulty-ae', 'AE');
    printOverrideTerm($track, 'inclusivity-disabilities', 'inclusivity-disabilities-physical', 'Motoria');
    printOverrideTerm($track, 'hiking-difficulty', 'hiking-difficulty-e', 'E');

    app(PrintService::class)->generate($track, 'it');
    $pdf = tempnam(sys_get_temp_dir(), 'scheda').'.pdf';
    file_put_contents($pdf, $this->compiler->pdf);
    $pages = (int) trim((string) shell_exec(sprintf('gs -q -dNODISPLAY -dNOSAFER -c %s 2>/dev/null', escapeshellarg('('.$pdf.') (r) file runpdfbegin pdfpagecount = quit'))));
    $page = fn (int $n) => (string) shell_exec(sprintf('gs -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -dFirstPage=%d -dLastPage=%d -sOutputFile=- %s 2>/dev/null', $n, $n, escapeshellarg($pdf)));
    // L'estrazione di ghostscript perde gli spazi e le etichette sono in maiuscolo: si confronta
    // il testo senza spazi e in minuscolo.
    $flat = fn (string $text) => mb_strtolower((string) preg_replace('/\s+/u', '', $text));
    $one = $flat($page(1));
    $two = $flat($page(2));
    @unlink($pdf);

    // Pagina 1: testata, stato, in breve, dati catastali e indirizzo del sito. Pagina 2: solo la
    // mappa, con il credito; né testata né piè di pagina.
    expect($pages)->toBe(2);
    expect($one)->toContain('scamartini')->toContain('validazionecatastale')->toContain('ilsentieroinbreve')
        ->toContain('codicecatastale')->toContain('www.example.test/sentieri')
        ->and(strpos($one, 'sentierochiusoperfrana'))->toBeLessThan(strpos($one, 'validazionecatastale'))
        ->and(strpos($one, 'validazionecatastale'))->toBeLessThan(strpos($one, 'ilsentieroinbreve'));
    expect($one)->not->toContain('openstreetmap');
    expect($one)->not->toContain('ilsentierosale');
    expect($two)->toContain('openstreetmap');
    expect($two)->not->toContain('scamartini');
    expect($two)->not->toContain('regioneautonoma');
})->skip(! is_executable('/usr/bin/xelatex') || trim((string) shell_exec('command -v gs')) === '', 'xelatex o ghostscript non installati');

it('compila la scheda del POI in due facciate, con la sola mappa in pagina 2', function () {
    $this->compiler->real = true;
    $poi = printOverridePoi([
        'description' => ['it' => '<p>'.str_repeat('Un santuario campestre molto lungo da descrivere. ', 80).'</p>'],
        'forestas' => ['url' => 'https://www.example.test/da-vedere/san-mauro'],
        'taxonomy_where' => ['R40182' => ['it' => 'Sorgono', '_admin_level' => 8], '81' => ['it' => 'Area T (Centrale)']],
    ]);

    app(PrintService::class)->generate($poi, 'it');
    $pdf = tempnam(sys_get_temp_dir(), 'scheda').'.pdf';
    file_put_contents($pdf, $this->compiler->pdf);
    $pages = (int) trim((string) shell_exec(sprintf('gs -q -dNODISPLAY -dNOSAFER -c %s 2>/dev/null', escapeshellarg('('.$pdf.') (r) file runpdfbegin pdfpagecount = quit'))));
    $page = fn (int $n) => mb_strtolower((string) preg_replace('/\s+/u', '', (string) shell_exec(sprintf('gs -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -dFirstPage=%d -dLastPage=%d -sOutputFile=- %s 2>/dev/null', $n, $n, escapeshellarg($pdf)))));
    $one = $page(1);
    $two = $page(2);
    @unlink($pdf);

    expect($pages)->toBe(2);
    expect($one)->toContain('nuragheserbissi')->toContain('ilpuntoinbreve')->toContain('www.example.test/da-vedere');
    expect($one)->not->toContain('santuariocampestre');
    expect($two)->toContain('openstreetmap');
    expect($two)->not->toContain('nuragheserbissi');
})->skip(! is_executable('/usr/bin/xelatex') || trim((string) shell_exec('command -v gs')) === '', 'xelatex o ghostscript non installati');

it('compila la mappa d\'insieme del package', function () {
    $this->compiler->real = true;
    $track = printOverrideTrack(['name' => ['it' => 'Monte Corrasi & 100%']], ['ref' => 'B 401']);

    // La mappa d'insieme resta la view del package: nessuno stile dello shard da caricare.
    app()->call([new BuildOverviewMapJob([$track->id], 'it'), 'handle']);

    expect($this->compiler->sources)->toHaveCount(1)
        ->and($this->compiler->sources[0])->not->toContain('\usepackage{forestas}')
        ->and(strlen((string) $this->compiler->pdf))->toBeGreaterThan(1000)
        ->and(str_starts_with((string) $this->compiler->pdf, '%PDF'))->toBeTrue();
})->skip(! is_executable('/usr/bin/xelatex'), 'xelatex non installato');

describe('print_url nel dettaglio del POI', function () {
    it('c\'è a dominio acceso', function () {
        config(['wm-package.features.print.enabled' => true]);
        $provider = app()->getProvider(WmPackageServiceProvider::class);
        // Le rotte dei domini si caricano al boot: si rilancia lo stesso metodo del provider.
        (new ReflectionMethod($provider, 'registerEnabledDomains'))->invoke($provider);
        app('router')->getRoutes()->refreshNameLookups();
        $poi = printOverridePoi();

        $data = (new EcPoiDetailController)->show($poi->id)->getData(true);

        expect($data['properties']['print_url'] ?? null)->toBe(route('print.show', ['type' => 'ec-poi', 'id' => $poi->id]));
    });

    it('non c\'è a dominio spento', function () {
        config(['wm-package.features.print.enabled' => false]);
        $poi = printOverridePoi();

        $data = (new EcPoiDetailController)->show($poi->id)->getData(true);

        expect($data['properties'])->not->toHaveKey('print_url');
    });
});
