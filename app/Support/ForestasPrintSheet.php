<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EcPoi;
use App\Models\EcTrack;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Throwable;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\Print\LatexText;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityDetailService;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityService;

/**
 * I dati di Forestas per le schede PDF (oc:8982), pronti per gli override delle view di stampa
 * (`resources/views/vendor/wm-package/print/`). Ogni stringa restituita è già LaTeX-sicura
 * (`LatexText::escape()` o `::fromHtml()`): la view la stampa con `{!! !!}`. Le parti senza dati
 * escono vuote (null o lista vuota) e la view non le mostra; i dati delle tabelle che il sito
 * mostra sempre escono con il trattino «—».
 *
 * Contenuti, etichette e logica delle scale seguono la pagina di dettaglio del sito WordPress
 * (tema `wp-forestas/themes/forestas-child`, `wp-package/track.php`, `wp-package/poi.php`,
 * `inc/dettaglio.php` e il registro degli stati di `inc/statuses.php`, oc:9000), che legge gli
 * stessi dati di `properties.forestas`: la scheda stampata e la pagina web dicono la stessa cosa
 * (R17). I colori sono quelli del design system SARDEGNA Sentieri, per ruolo (R16).
 */
final class ForestasPrintSheet
{
    /** Il segno dei dati mancanti, come sul sito (`forestas_dettaglio_valore()`). */
    public const MISSING = '—';

    /**
     * Le attività della riga «Difficoltà» dei dati brevi, con il nome che dà il sito
     * (`forestas_dettaglio_difficolta()`): tassonomia => chiave di traduzione.
     */
    public const ACTIVITY_TAXONOMIES = [
        'hiking-difficulty' => 'Hiking',
        'mtb-difficulty' => 'Mountain bike',
        'horse-difficulty' => 'Horse riding',
        'nordic-walking-difficulty' => 'Nordic walking',
        'soft-mobility-difficulty' => 'Soft mobility',
        'educational-trails-difficulty' => 'Educational trail',
    ];

    /**
     * Scala della validazione catastale (registro degli stati del tema, oc:9000): stato => livello
     * su VALIDATION_TOTAL, con l'etichetta del sito. «Non verificato» è lo stato di assenza (0).
     */
    private const VALIDATION_LEVELS = [
        'validation-status-unverified' => [0, 'Validation: not verified'],
        'validation-status-under-review' => [2, 'Validation: under review'],
        'validation-status-pre-registration' => [3, 'Validation: pre-registration or maintenance'],
        'validation-status-validated' => [4, 'Validation: validated'],
        'validation-status-certified' => [5, 'Validation: certified'],
    ];

    private const VALIDATION_TOTAL = 5;

    /**
     * Colore di stato del design system (`st-*`, «sempre con numero ed etichetta») per livello di
     * validazione: la scala del design system ha quattro stati (4 Percorribile/validato,
     * 3 In verifica, 2 In revisione, 1 Non percorribile), quella del catasto cinque.
     */
    private const VALIDATION_COLORS = [2 => 'st-2', 3 => 'st-3', 4 => 'st-4', 5 => 'st-4'];

    /**
     * Scala della percorribilità (famiglia del package, ordine di `WalkabilityTaxonomyInstaller`):
     * «Sconosciuta» è lo stato di assenza (0), gli altri valgono la loro posizione.
     */
    private const WALKABILITY_LEVELS = [
        'unknown' => [0, null],
        'not-walkable' => [2, 'st-1'],
        'partially-walkable' => [3, 'st-3'],
        'walkable' => [4, 'st-4'],
    ];

    private const WALKABILITY_TOTAL = 4;

    /** Gradi di accessibilità dell'Allegato H: sigla => [livello, chiave del nome esteso]. */
    private const ACCESSIBILITY_GRADES = [
        'AT' => [1, 'Accessible for tourists'],
        'AE' => [2, 'Accessible for hikers'],
        'AEE' => [3, 'Accessible for expert hikers'],
    ];

    /**
     * Le tipologie di disabilità della colonna Accessibilità: termine => [icona, chiave
     * dell'etichetta]. Le icone sono i disegni a tratto del tema in `resources/print/icons/`.
     */
    private const DISABILITIES = [
        'inclusivity-disabilities-physical' => ['disability-motor', 'Motor disability'],
        'inclusivity-disabilities-sensory' => ['disability-sensory', 'Sensory disability'],
        'inclusivity-disabilities-cognitive' => ['disability-cognitive', 'Cognitive disability'],
    ];

    /**
     * Il tipo di percorso: [tipo del sito, colore `tipo-*` del design system].
     */
    private const ROUTE_TYPES = [
        'route-type-trail' => ['sentiero', 'tipo-sentiero'],
        'route-type-itinerary' => ['itinerario', 'tipo-itinerario'],
        'route-type-walk' => ['cammino', 'tipo-cammino'],
    ];

    /** Ruoli degli enti nell'ordine del sito (`forestas_dettaglio_ruoli()`): chiave => etichetta. */
    private const ORGANIZATION_ROLES = [
        'manager' => 'Managing authority',
        'maintainer' => 'Maintaining body',
        'surveyor' => 'Surveying body',
        'operator' => 'Operator',
        'forest_complex' => 'Forest complex',
    ];

    /**
     * Il modello di Forestas: la rotta del package risolve `ec-poi` con il modello del package
     * (`wm-package.ec_poi_model` non è impostato), che non ha le relazioni di Forestas.
     */
    public static function trackModel(Model $model): EcTrack
    {
        return $model instanceof EcTrack ? $model : EcTrack::query()->findOrFail($model->getKey());
    }

    public static function poiModel(Model $model): EcPoi
    {
        return $model instanceof EcPoi ? $model : EcPoi::query()->findOrFail($model->getKey());
    }

    /**
     * @param  array<string, mixed>  $forestas  `ForestasTrackDetail::build($track)`
     * @return array<string, mixed>
     */
    public static function track(EcTrack $track, array $forestas, string $locale): array
    {
        $track->loadMissing('taxonomyTerms.parent');
        $properties = (array) $track->properties;
        $routeType = $track->term('route-type');
        [$kind, $color] = self::ROUTE_TYPES[$routeType?->identifier] ?? self::ROUTE_TYPES['route-type-trail'];
        $places = self::places((array) ($properties['taxonomy_where'] ?? []), $locale);
        $validation = self::validation($track, $locale);
        $walkability = self::walkability($track, $locale);
        $ref = self::text($properties['ref'] ?? null);

        return [
            'kind' => $kind,
            'type' => [
                'text' => ($routeType === null ? null : self::termName($routeType, $locale)) ?? self::label('Trail', $locale),
                'color' => $color,
            ],
            'ref' => $ref,
            'name' => self::displayName($track->printTranslation('name', $locale), is_scalar($properties['ref'] ?? null) ? (string) $properties['ref'] : ''),
            // Occhiello della testata coerente con il tipo: «Scheda del sentiero / dell'itinerario / del cammino».
            'sheetLabel' => self::label(['itinerario' => 'Itinerary sheet', 'cammino' => 'Walk sheet'][$kind] ?? 'Trail sheet', $locale),
            'area' => self::areaText($places['areas'], $locale),
            'location' => self::locationText($places, $locale),
            'notice' => self::notice($track, $locale),
            // Il filetto laterale dell'avviso prende il colore dello stato (README §5); senza uno
            // stato di percorribilità resta neutro.
            'noticeColor' => $walkability['color'],
            'validation' => $validation,
            'walkability' => $walkability,
            'accessibility' => self::accessibility($track, $locale),
            'inclusivity' => self::inclusivity((array) ($forestas['inclusivity'] ?? []), $locale),
            'atAGlance' => self::label(['itinerario' => 'The itinerary at a glance', 'cammino' => 'The walk at a glance'][$kind] ?? 'The trail at a glance', $locale),
            'facts' => self::facts($track, $locale),
            'dataTitle' => self::label(['itinerario' => 'Itinerary data', 'cammino' => 'Walk data'][$kind] ?? 'Register and elevation data', $locale),
            'keyData' => self::keyData($track, $kind, $forestas, $locale),
            'link' => self::link('track', $forestas['sardegnasentieri_id'] ?? null, (int) $track->id, $locale),
            'labels' => self::labels($locale),
        ];
    }

    /**
     * @param  array<string, mixed>  $forestas  `ForestasPoiDetail::build($poi)`
     * @return array<string, mixed>
     */
    public static function poi(EcPoi $poi, array $forestas, string $locale): array
    {
        /** @var TaxonomyPoiType|null $poiType */
        $poiType = $poi->taxonomyPoiTypes()->first();
        $typeName = $poiType === null ? null : self::text((string) $poiType->getTranslation('name', $locale));
        $places = self::places((array) ($poi->properties['taxonomy_where'] ?? []), $locale);
        $labels = self::labels($locale);

        $rows = [
            ['label' => $labels['Point type'], 'value' => $typeName ?? self::MISSING],
            ['label' => $labels['Municipality'], 'value' => $places['municipalities'] === [] ? self::MISSING : implode(', ', $places['municipalities'])],
            ['label' => $labels['Geographic area'], 'value' => $places['areas'] === [] ? self::MISSING : implode(', ', $places['areas'])],
        ];
        $code = self::text($forestas['code'] ?? null);
        if ($code !== null) {
            $rows[] = ['label' => $labels['Code'], 'value' => $code];
        }
        $rows = [...$rows, ...self::organizations((array) ($forestas['organizations'] ?? []), $locale)];

        return [
            'type' => ['text' => $typeName ?? $labels['Point of interest'], 'color' => 'tipo-da-vedere'],
            'area' => self::areaText($places['areas'], $locale),
            'location' => self::locationText($places, $locale),
            'rows' => $rows,
            'directions' => self::html(self::localized($forestas['directions'] ?? null, $locale)),
            'link' => self::link('poi', $forestas['sardegnasentieri_id'] ?? null, (int) $poi->id, $locale),
            'labels' => $labels,
        ];
    }

    /**
     * Le etichette proprie della scheda di Forestas (chiave inglese, `lang/*.json`), già escaped.
     *
     * @return array<string, string>
     */
    public static function labels(string $locale): array
    {
        $keys = [
            'Notice', 'Walkability', 'Trail register validation', 'Accessibility', 'Not yet available',
            'Not classified', 'status updated on', 'Note:', 'Photogallery', 'See the page on the website',
            'Trail for everyone', 'Inclusivity score', 'Directions', 'Code',
            'Point of interest sheet', 'Point of interest', 'Point type', 'Municipality',
            'Geographic area', 'At a glance', 'The point of interest', 'Map',
        ];
        $labels = [];
        foreach ($keys as $key) {
            $labels[$key] = self::label($key, $locale);
        }
        // Il copyright RAS chiude ogni pagina (design system, README §2, PieDiPagina).
        $labels['Footer copyright'] = self::label('© :year Autonomous Region of Sardinia · Fo.Re.S.T.A.S. Agency', $locale, ['year' => date('Y')]);

        return $labels;
    }

    /**
     * Il disegno di un'icona di `resources/print/icons/` in comandi TikZ (libreria `svg.path`):
     * XeLaTeX non legge SVG, e la cartella di lavoro del compilatore accetta solo i file che le
     * allega il package. Si leggono i soli elementi `circle` e `path` dei disegni a tratto del tema
     * (viewBox 24), con i dati del tracciato controllati: solo comandi SVG e numeri.
     */
    public static function icon(string $name): string
    {
        $path = resource_path('print/icons/'.basename($name).'.svg');
        if (! File::isFile($path)) {
            return '';
        }
        $svg = File::get($path);
        $commands = [];
        preg_match_all('/<circle\s+cx="([\d.]+)"\s+cy="([\d.]+)"\s+r="([\d.]+)"\s*\/>/', $svg, $circles, PREG_SET_ORDER);
        foreach ($circles as [, $cx, $cy, $r]) {
            $commands[] = sprintf('\draw (%spt,%spt) circle[radius=%spt];', $cx, $cy, $r);
        }
        preg_match_all('/<path\s+d="([^"]+)"\s*\/>/', $svg, $paths);
        foreach ($paths[1] as $d) {
            if (preg_match('/^[MmLlHhVvCcSsQqTtAaZz\d\s.,-]+$/', $d) === 1) {
                $commands[] = '\draw svg {'.$d.'};';
            }
        }

        return implode('', $commands);
    }

    /**
     * La validazione catastale (`validation-status`), sulla scala del sito.
     *
     * @return array{level: int, total: int, color: string, label: string, value: string, badge: string}
     */
    private static function validation(EcTrack $track, string $locale): array
    {
        $term = $track->term('validation-status');
        $identifier = $term === null ? 'validation-status-unverified' : (string) $term->identifier;
        [$level, $key] = self::VALIDATION_LEVELS[$identifier] ?? [0, null];
        $name = $key !== null
            ? self::label($key, $locale)
            : (($term === null ? null : self::termName($term, $locale)) ?? self::label('Validation: not verified', $locale));

        return [
            'level' => $level,
            'total' => self::VALIDATION_TOTAL,
            'color' => self::VALIDATION_COLORS[$level] ?? 'ink-3',
            'label' => $name,
            'value' => self::scaleValue($level, self::VALIDATION_TOTAL, $name, $locale),
            'badge' => $level.' · '.$name,
        ];
    }

    /**
     * La percorribilità del Catasto (oc:8989), sulla scala del sito, più la fascia: lo stato con
     * la data dell'ultima registrazione e la nota, oppure «Dato non ancora disponibile».
     *
     * @return array{level: int, total: int, color: string, value: string, band: array{title: string, body: string|null}}
     */
    private static function walkability(EcTrack $track, string $locale): array
    {
        $missing = self::label('Not yet available', $locale);
        $term = null;
        if (config('wm-package.features.trail_registry.enabled')) {
            $term = $track->term(WalkabilityService::taxonomyIdentifier());
        }

        $level = 0;
        $color = null;
        $name = $missing;
        // Senza stato il sito scrive solo «Dato non ancora disponibile», senza «0 su 4».
        $value = $missing;
        if ($term !== null) {
            $suffix = substr((string) $term->identifier, strlen(WalkabilityService::taxonomyIdentifier()) + 1);
            [$level, $color] = self::WALKABILITY_LEVELS[$suffix] ?? [0, null];
            $name = self::termName($term, $locale) ?? $missing;
            $value = self::scaleValue($level, self::WALKABILITY_TOTAL, $name, $locale);
        }

        $band = ['title' => self::label('Walkability', $locale), 'body' => $missing];
        if ($level > 0) {
            $detail = app(WalkabilityDetailService::class)->forTrack($track);
            $date = self::date($detail['last_update'] ?? null);
            $note = self::text($detail['description'] ?? null);
            $band = [
                'title' => $name.($date === null ? '' : ' · '.self::label('status updated on', $locale).' '.$date),
                'body' => $note === null ? null : self::label('Note:', $locale).' '.$note,
            ];
        }

        return [
            'level' => $level,
            'total' => self::WALKABILITY_TOTAL,
            'color' => $color ?? 'ink-3',
            'value' => $value,
            'band' => $band,
        ];
    }

    /**
     * La colonna Accessibilità del sito: grado AT · AE · AEE (`inclusivity-difficulty`) e le tre
     * tipologie di disabilità (`inclusivity-disabilities`), accese o spente.
     *
     * @return array{level: int, total: int, color: string, value: string, grades: list<string>, disabilities: list<array{on: bool, label: string, icon: string}>}
     */
    private static function accessibility(EcTrack $track, string $locale): array
    {
        $term = $track->term('inclusivity-difficulty');
        $grade = $term === null ? '' : strtoupper((string) preg_replace('/^inclusivity-difficulty-/', '', (string) $term->identifier));
        [$level, $key] = self::ACCESSIBILITY_GRADES[$grade] ?? [0, null];

        $active = $track->terms('inclusivity-disabilities')->pluck('identifier')->all();
        $disabilities = [];
        foreach (self::DISABILITIES as $identifier => [$icon, $label]) {
            $disabilities[] = [
                'on' => in_array($identifier, $active, true),
                'label' => self::label($label, $locale),
                'icon' => self::icon($icon),
            ];
        }

        return [
            'level' => $level,
            'total' => count(self::ACCESSIBILITY_GRADES),
            // Acceso = elemento attivo dell'interfaccia: sar-blu (README §2, «checkbox attive»).
            'color' => $level > 0 ? 'sar-blu' : 'ink-3',
            'value' => $key === null ? self::label('Not classified', $locale) : $grade.' · '.self::label($key, $locale),
            'grades' => array_keys(self::ACCESSIBILITY_GRADES),
            'disabilities' => $disabilities,
        ];
    }

    private static function scaleValue(int $level, int $total, string $name, string $locale): string
    {
        return self::label(':level of :total', $locale, ['level' => $level, 'total' => $total]).' · '.$name;
    }

    /**
     * «Il sentiero in breve»: i quattro dati del sito, con il trattino se mancano.
     *
     * @return list<array{label: string, value: string}>
     */
    private static function facts(EcTrack $track, string $locale): array
    {
        $min = self::technical($track, 'ele_min');
        $max = self::technical($track, 'ele_max');

        return [
            ['label' => self::label('Length', $locale), 'value' => self::measure(self::technical($track, 'distance'), 'km', 1, $locale)],
            ['label' => self::label('Total elevation gain', $locale), 'value' => self::measure(self::technical($track, 'ascent'), 'm', 0, $locale)],
            ['label' => self::label('Walking time', $locale), 'value' => self::duration(self::technical($track, 'duration_forward'))],
            ['label' => self::label('Elevation min — max', $locale), 'value' => is_numeric($min) && is_numeric($max) ? (int) $min.' — '.(int) $max : self::MISSING],
        ];
    }

    /**
     * I dati catastali e altimetrici che stanno nella pagina 1 (oc:8982, R20): solo quelli che la
     * scheda non mostra già altrove (lunghezza, dislivello, tempo e quote sono in «In breve», lo
     * stato di validazione nel blocco di stato) e che contano per chi cammina. Le righe senza dato
     * non escono: lo spazio è poco. Itinerari e cammini non hanno i dati del catasto, come sul sito.
     *
     * @param  array<string, mixed>  $forestas  `ForestasTrackDetail::build($track)`
     * @return list<array{label: string, value: string}>
     */
    private static function keyData(EcTrack $track, string $kind, array $forestas, string $locale): array
    {
        $properties = (array) $track->properties;
        $roundtrip = isset($properties['roundtrip']) ? (bool) $properties['roundtrip'] : null;
        $difficulty = implode(', ', array_map(fn ($row) => $row['activity'].' '.$row['difficulty'], self::activities($track, $locale)));

        $rows = [
            ['label' => self::label('Start', $locale), 'value' => self::text(self::localized($properties['from'] ?? null, $locale))],
            ['label' => self::label('End', $locale), 'value' => self::text(self::localized($properties['to'] ?? null, $locale))],
            ['label' => self::label('Round trip', $locale), 'value' => $roundtrip === null ? null : self::label($roundtrip ? 'Yes' : 'No', $locale)],
            ['label' => self::label('Difficulty level', $locale), 'value' => $difficulty === '' ? null : $difficulty],
        ];
        if ($kind === 'sentiero') {
            $rows[] = ['label' => self::label('Register code', $locale), 'value' => self::text($forestas['registry_code'] ?? null)];
            $rows[] = ['label' => self::label('Survey date', $locale), 'value' => self::date($forestas['survey_date'] ?? null)];
        }

        return array_values(array_filter($rows, fn (array $row) => $row['value'] !== null));
    }

    /**
     * Il rimando alla pagina di dettaglio del sito WordPress: l'URL per il QR code (caratteri speciali
     * escaped per il pacchetto `qrcode`) e lo stesso URL leggibile (escaped per LaTeX). Null se il sito
     * non è configurato (`forestas.wp_site_url`).
     *
     * PROVVISORIO (oc:8982): l'URL con lo slug lo costruisce il plugin wp-package
     * (`wmwp_detail_url()`), che lo shard non conosce. Qui si usa il redirect del plugin
     * `?wmwp_detail=<tipo>&wmwp_id=<chiave>`, con il nid di Drupal come chiave stabile (non cambia
     * con il reset notturno di dev e UAT; filtro `wmwp_detail_source` del tema) e l'id dello shard per
     * gli elementi creati in Nova. Da confermare sul codice del plugin.
     *
     * @return array{qr: string, text: string}|null
     */
    private static function link(string $type, mixed $nid, int $id, string $locale): ?array
    {
        $base = config('forestas.wp_site_url');
        if (! is_string($base) || trim($base) === '') {
            return null;
        }
        $key = is_scalar($nid) && trim((string) $nid) !== '' ? trim((string) $nid) : (string) $id;
        $url = rtrim(trim($base), '/').($locale === 'it' ? '' : '/'.$locale).'/?wmwp_detail='.$type.'&wmwp_id='.rawurlencode($key);

        return [
            'qr' => strtr($url, ['\\' => '\\\\', '#' => '\\#', '%' => '\\%', '&' => '\\&', '_' => '\\_', '~' => '\\~', '$' => '\\$', '^' => '\\^', '{' => '\\{', '}' => '\\}']),
            'text' => LatexText::escape(preg_replace('#^https?://#', '', $url) ?? $url),
        ];
    }

    /**
     * Gli enti per ruolo (`ForestasDetailParts::organizations()`), una riga per ente.
     *
     * @param  array<string, mixed>  $organizations
     * @return list<array{label: string, value: string}>
     */
    private static function organizations(array $organizations, string $locale): array
    {
        $rows = [];
        foreach (self::ORGANIZATION_ROLES as $role => $label) {
            foreach ((array) ($organizations[$role] ?? []) as $ente) {
                $name = is_array($ente) ? self::text(self::localized($ente['name'] ?? null, $locale)) : null;
                if ($name !== null) {
                    $rows[] = ['label' => self::label($label, $locale), 'value' => $name];
                }
            }
        }

        return $rows;
    }

    /**
     * I luoghi del sito (`forestas_dettaglio_luoghi()`) da `properties.taxonomy_where`: comuni
     * (livello 8), la prima provincia (livello 6) e le aree geografiche (senza livello, nome che
     * comincia con «Area»). Le zone catastali e la regione non si mostrano.
     *
     * @param  array<string|int, mixed>  $where
     * @return array{areas: list<string>, municipalities: list<string>, province: string|null}
     */
    private static function places(array $where, string $locale): array
    {
        $places = ['areas' => [], 'municipalities' => [], 'province' => null];
        foreach ($where as $identifier => $names) {
            if (! is_array($names)) {
                continue;
            }
            $level = (int) ($names['_admin_level'] ?? 0);
            // Nello shard alcuni nomi sono l'identifier (en: «R19621461»): quei valori non contano.
            $translations = array_filter(
                $names,
                fn ($value, $key) => is_string($value) && ! str_starts_with((string) $key, '_') && $value !== (string) $identifier,
                ARRAY_FILTER_USE_BOTH,
            );
            $raw = self::localized($translations, $locale);
            $name = self::text($raw);
            if ($name === null) {
                continue;
            }
            if ($level === 8) {
                $places['municipalities'][] = $name;
            } elseif ($level === 6 && $places['province'] === null) {
                $places['province'] = $name;
            } elseif ($level === 0 && stripos((string) $raw, 'Area ') === 0) {
                $places['areas'][] = $name;
            }
        }

        return $places;
    }

    /** @param  list<string>  $areas */
    private static function areaText(array $areas, string $locale): ?string
    {
        if ($areas === []) {
            return null;
        }

        return self::label(count($areas) === 1 ? 'Geographic area' : 'Geographic areas', $locale).' '.implode(', ', $areas);
    }

    /** @param  array{municipalities: list<string>, province: string|null}  $places */
    private static function locationText(array $places, string $locale): ?string
    {
        $parts = array_filter([
            implode(', ', $places['municipalities']),
            $places['province'] === null ? '' : self::label('Province', $locale).' '.$places['province'],
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Il nome senza il codice fra parentesi in coda, quando il codice è già accanto al tipo
     * (`forestas_dettaglio_nome()`): «Anello di Munduge (B 532A)» con ref «B-532A».
     */
    private static function displayName(string $name, string $ref): string
    {
        $signs = fn (string $text): string => mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text));
        if ($signs($ref) !== '' && preg_match('/^(.*?)\s*\(([^()]*)\)\s*$/u', $name, $match) === 1
            && $signs($match[2]) === $signs($ref) && trim($match[1]) !== '') {
            $name = trim($match[1]);
        }

        return LatexText::escape($name);
    }

    /**
     * L'avviso (`not_accessible`, oc:8981): solo se pubblicato e con un testo.
     *
     * @return array{message: string, date: string|null}|null
     */
    private static function notice(EcTrack $track, string $locale): ?array
    {
        $properties = (array) $track->properties;
        if (! WmEcTrack::isNotAccessibleOn($properties['not_accessible'] ?? null)) {
            return null;
        }

        $raw = $properties['not_accessible_message'] ?? null;
        $message = WmEcTrack::notAccessibleMessageText($raw, $locale)
            ?? WmEcTrack::notAccessibleMessageText($raw, 'it');
        if ($message === null) {
            return null;
        }

        return [
            'message' => LatexText::escape($message),
            'date' => self::date($properties[WmEcTrack::NOT_ACCESSIBLE_UPDATED_AT] ?? null),
        ];
    }

    /**
     * Una riga per attività: il nome del sito e il grado (nome del termine).
     *
     * @return list<array{activity: string, difficulty: string}>
     */
    private static function activities(EcTrack $track, string $locale): array
    {
        $rows = [];
        foreach (self::ACTIVITY_TAXONOMIES as $taxonomy => $activity) {
            $term = $track->term($taxonomy);
            $difficulty = $term === null ? null : self::termName($term, $locale);
            if ($difficulty !== null) {
                $rows[] = ['activity' => self::label($activity, $locale), 'difficulty' => $difficulty];
            }
        }

        return $rows;
    }

    /**
     * Il blocco «Sentiero per tutti»: esiste solo se c'è la classe, cioè tutti e tre i punteggi.
     *
     * @param  array<string, mixed>  $inclusivity  `properties.forestas.inclusivity`
     * @return array{score: string, class: string, rows: list<array{label: string, value: string}>}|null
     */
    private static function inclusivity(array $inclusivity, string $locale): ?array
    {
        $class = self::text($inclusivity['class'] ?? null);
        if (! isset($inclusivity['score']) || $class === null) {
            return null;
        }

        $value = fn (mixed $raw) => self::text($raw) ?? self::MISSING;

        return [
            'score' => $value($inclusivity['score']),
            'class' => $class,
            'rows' => [
                ['label' => self::label('Independent use', $locale), 'value' => $value($inclusivity['independent_use'] ?? null)],
                ['label' => self::label('Assistance available', $locale), 'value' => $value($inclusivity['assistance'] ?? null)],
                ['label' => self::label('Physical accessibility', $locale), 'value' => $value($inclusivity['physical'] ?? null)],
                ['label' => self::label('Available aids', $locale), 'value' => $value($inclusivity['aids'] ?? null)],
                ['label' => self::label('Digital accessibility', $locale), 'value' => $value($inclusivity['digital'] ?? null)],
            ],
        ];
    }

    /** Valore tecnico con la precedenza del dettaglio (manuale > OSM > DEM), poi `properties`. */
    private static function technical(EcTrack $track, string $field): mixed
    {
        $value = $track->classifyField($track, $field)['currentValue'] ?? null;

        return ($value === null || $value === '') ? ($track->properties[$field] ?? null) : $value;
    }

    /** Numero con la virgola (o il punto, in inglese) e l'unità, come `number_format_i18n()`; o il trattino. */
    private static function measure(mixed $number, string $unit, int $decimals, string $locale): string
    {
        if (! is_numeric($number)) {
            return self::MISSING;
        }
        [$decimal, $thousands] = $locale === 'en' ? ['.', ','] : [',', '.'];

        return number_format((float) $number, $decimals, $decimal, $thousands).' '.$unit;
    }

    /** Minuti come «3 h 20 min», «45 min», «2 h» (`forestas_dettaglio_durata()`); o il trattino. */
    private static function duration(mixed $minutes): string
    {
        if (! is_numeric($minutes) || (int) $minutes <= 0) {
            return self::MISSING;
        }
        $hours = intdiv((int) $minutes, 60);
        $rest = (int) $minutes % 60;

        return trim(($hours > 0 ? $hours.' h ' : '').($rest > 0 ? $rest.' min' : ''));
    }

    /** @param  array<string, string|int>  $replace */
    private static function label(string $key, string $locale, array $replace = []): string
    {
        return LatexText::escape((string) __($key, $replace, $locale));
    }

    private static function termName(TaxonomyTerm $term, string $locale, bool $capitalize = false): ?string
    {
        $name = self::localized($term->getTranslations('name'), $locale);
        if ($capitalize && is_string($name)) {
            $name = mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
        }

        return self::text($name);
    }

    /**
     * Il testo nella lingua chiesta, poi l'italiano, poi la prima lingua che ne ha. Una lista di
     * testi (come `info_utili`) si unisce in paragrafi.
     */
    private static function localized(mixed $value, string $locale): ?string
    {
        if (! is_array($value)) {
            return is_scalar($value) ? (string) $value : null;
        }

        foreach ([$value[$locale] ?? null, $value['it'] ?? null, ...array_values($value)] as $candidate) {
            if (is_array($candidate)) {
                $candidate = implode("\n", array_filter($candidate, 'is_string'));
            }
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : LatexText::escape($text);
    }

    private static function html(?string $html): ?string
    {
        $latex = LatexText::fromHtml($html);

        return trim($latex) === '' ? null : $latex;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->timezone('Europe/Rome')->format('d/m/Y');
        } catch (Throwable) {
            return null;
        }
    }
}
