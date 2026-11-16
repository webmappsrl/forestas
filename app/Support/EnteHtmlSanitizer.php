<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Filtra l'HTML di descrizione e contatti degli enti con una whitelist (oc:8965).
 *
 * Il testo arriva da Drupal con la sua formattazione e finisce, attraverso il JSON degli enti,
 * nelle pagine del sito WordPress: si tengono solo paragrafi, titoli, link, enfasi ed elenchi.
 * Lo usa il mutator di `Ente`, quindi vale per ogni scrittura (import, Nova, tinker).
 */
final class EnteHtmlSanitizer
{
    /** Tag e attributi ammessi, nella sintassi `HTML.Allowed` di HTMLPurifier. */
    public const ALLOWED_HTML = 'p,br,h2,h3,h4,h5,h6,a[href],em,strong,ul,ol,li';

    /** Schemi ammessi negli `href`: un link senza uno di questi perde il tag e tiene il testo. */
    private const LINK_SCHEMES = '#^(https?:|mailto:|tel:)#i';

    private static ?HTMLPurifier $purifier = null;

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $clean = trim(self::purifier()->purify(self::unwrapRelativeLinks($html)));

        // Senza testo visibile è un campo vuoto: il Tiptap svuotato salva `<p></p>`.
        $visible = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[\s\x{00A0}]+/u', '', $visible) === '' ? null : $clean;
    }

    /**
     * Toglie il tag ai link con un indirizzo relativo o senza `href`, e ne tiene il testo.
     * HTMLPurifier filtra solo gli schemi: un `/node/123` di Drupal passerebbe, e su WordPress
     * porterebbe a una pagina sbagliata. Stessa regola di `DiaryTextService` di camminiditalia
     * (oc:8169). Gira prima di HTMLPurifier, così l'HTML finale lo scrive sempre lui, nella
     * stessa forma, che ci siano link o no.
     */
    private static function unwrapRelativeLinks(string $html): string
    {
        if (stripos($html, '<a') === false) {
            return $html;
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="ente-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($doc->getElementsByTagName('a')) as $link) {
            /** @var DOMElement $link */
            if (preg_match(self::LINK_SCHEMES, trim($link->getAttribute('href')))) {
                continue;
            }
            while ($link->firstChild !== null) {
                $link->parentNode?->insertBefore($link->firstChild, $link);
            }
            $link->parentNode?->removeChild($link);
        }

        $root = $doc->getElementById('ente-root');
        if ($root === null) {
            return $html;
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        // Cache sotto storage/, come nel package (HasEmbeddableRichText): il default la scrive in
        // vendor/, che nell'immagine messa in sicurezza non è scrivibile.
        $cachePath = storage_path('framework/cache/htmlpurifier');
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0775, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED_HTML);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        $config->set('Cache.SerializerPath', $cachePath);

        return self::$purifier = new HTMLPurifier($config);
    }
}
