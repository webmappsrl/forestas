<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Il roadbook di Drupal come blocco dei Blocchi Dettaglio della traccia (`properties.config_detail`,
 * oc:8959): un blocco `info` con una riga per tappa, come lo mostra sardegnasentieri.it («Tappa 1»,
 * «Tappa 2»…). La tappa N di una lingua è il suo paragrafo N; titolo e testo escono solo nelle lingue
 * in cui la tappa ha un testo.
 *
 * Il blocco è uno specchio di Drupal (decisione del dev): l'import lo riscrive, gli altri blocchi
 * aggiunti in Nova restano. Si riconosce dai titoli delle righe, tutte «Tappa N» (o la traduzione),
 * perché il package, al salvataggio in Nova, tiene di ogni blocco solo `box_type` e `items`: un segno
 * messo dall'import si perderebbe.
 */
final class RoadbookBlock
{
    /** Il tipo di blocco del package (`ConfigDetailResolver::INFO_BOX_TYPE`). */
    private const BOX_TYPE = 'info';

    /** Il titolo della tappa per lingua, le lingue di `wm-tab-translatable.locales`. */
    private const STAGE_LABELS = [
        'it' => 'Tappa',
        'en' => 'Stage',
        'fr' => 'Étape',
        'es' => 'Etapa',
        'de' => 'Etappe',
    ];

    /**
     * Il nuovo `config_detail`: il blocco del roadbook al posto di quello precedente (o in testa, se
     * non c'era), tolto se le tappe mancano; gli altri blocchi restano nell'ordine in cui sono.
     *
     * @param  mixed  $configDetail  il `config_detail` salvato
     * @param  array<string, list<string>>|null  $stages  lingua => paragrafi propri
     * @return list<mixed>
     */
    public static function apply(mixed $configDetail, ?array $stages): array
    {
        $boxes = is_array($configDetail) ? array_values($configDetail) : [];
        $block = self::build($stages ?? []);

        $result = [];
        $placed = false;
        foreach ($boxes as $box) {
            if (! self::isRoadbook($box)) {
                $result[] = $box;

                continue;
            }
            // Il primo blocco del roadbook si sostituisce dov'è; eventuali copie si tolgono.
            if (! $placed && $block !== null) {
                $result[] = $block;
            }
            $placed = true;
        }

        if (! $placed && $block !== null) {
            array_unshift($result, $block);
        }

        return $result;
    }

    /**
     * @param  array<string, list<string>>  $stages
     * @return array{box_type: string, items: list<array{title: array<string, string>, content: array<string, string>}>}|null
     */
    private static function build(array $stages): ?array
    {
        $count = 0;
        foreach ($stages as $paragraphs) {
            $count = max($count, count($paragraphs));
        }
        if ($count === 0) {
            return null;
        }

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $title = [];
            $content = [];
            foreach ($stages as $lang => $paragraphs) {
                $text = $paragraphs[$i] ?? null;
                if (! is_string($text) || trim($text) === '') {
                    continue;
                }
                $title[$lang] = (self::STAGE_LABELS[$lang] ?? self::STAGE_LABELS['it']).' '.($i + 1);
                $content[$lang] = $text;
            }
            if ($content !== []) {
                $items[] = ['title' => $title, 'content' => $content];
            }
        }

        return $items === [] ? null : ['box_type' => self::BOX_TYPE, 'items' => $items];
    }

    /**
     * Un blocco `info` le cui righe hanno tutte un titolo «Tappa N» in almeno una lingua.
     */
    private static function isRoadbook(mixed $box): bool
    {
        if (! is_array($box) || ($box['box_type'] ?? null) !== self::BOX_TYPE) {
            return false;
        }
        $items = $box['items'] ?? null;
        if (! is_array($items) || $items === []) {
            return false;
        }

        foreach ($items as $item) {
            $titles = is_array($item) && is_array($item['title'] ?? null) ? $item['title'] : [];
            $matches = false;
            foreach ($titles as $lang => $title) {
                $label = self::STAGE_LABELS[$lang] ?? null;
                if ($label !== null && is_string($title) && preg_match('/^'.preg_quote($label, '/').' \d+$/u', $title) === 1) {
                    $matches = true;
                    break;
                }
            }
            if (! $matches) {
                return false;
            }
        }

        return true;
    }
}
