<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Models\TaxonomyPoiType;

class SyncTaxonomyPoiTypeTranslationsCommand extends Command
{
    private const DEFAULT_DICTIONARY = 'data/taxonomy_poi_types.json';

    protected $signature = 'taxonomy:sync-poi-type-i18n
                            {--dictionary= : Percorso al JSON (default: database/data/taxonomy_poi_types.json)}
                            {--dry-run : Mostra le modifiche senza salvare}
                            {--force : Aggiorna anche i record che hanno già it, en, fr, de ed es valorizzati}';

    protected $description = 'Aggiorna taxonomy_poi_types.name (it/en/fr/de/es) dal dizionario JSON per identifier, con fallback tra dizionario e valori già in DB.';

    /** @var list<string> */
    private const OPTIONAL_LOCALES = ['fr', 'de', 'es'];

    /** @var list<string> */
    private const SYNCED_LOCALES = ['it', 'en', 'fr', 'de', 'es'];

    /** @var array<string, array{it: ?string, en: ?string, fr: ?string, de: ?string, es: ?string}> */
    private array $dictionary = [];

    public function handle(): int
    {
        $path = $this->resolveDictionaryPath();
        if ($path === null) {
            $this->error('File dizionario non trovato o non leggibile. Usa --dictionary=/percorso/taxonomy_poi_types.json');

            return self::FAILURE;
        }

        $this->dictionary = $this->loadDictionary($path);
        if ($this->dictionary === []) {
            $this->error('Il dizionario non contiene voci con identifier valido.');

            return self::FAILURE;
        }

        $this->info('Dizionario: '.$path.' ('.count($this->dictionary).' identifier).');

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $updated = 0;
        $skipped = 0;
        $missingInDict = 0;
        $unresolvableLabels = 0;

        $query = TaxonomyPoiType::query()->orderBy('id');

        /** @var TaxonomyPoiType $row */
        foreach ($query->cursor() as $row) {
            $translations = $row->getTranslations('name');
            if (! $force && $this->hasAllSyncedLocalesNonEmpty($translations)) {
                $skipped++;

                continue;
            }

            $identifier = $row->identifier;
            $dictKey = is_string($identifier) ? strtolower($identifier) : '';
            $dict = $dictKey !== '' ? ($this->dictionary[$dictKey] ?? null) : null;

            if ($dict === null) {
                $missingInDict++;

                continue;
            }

            $currentIt = $this->stringLocale($translations, 'it');
            $currentEn = $this->stringLocale($translations, 'en');
            $resolution = $this->resolveLabels($dict, $currentIt, $currentEn);

            if ($resolution === null) {
                $unresolvableLabels++;

                continue;
            }

            [$newIt, $newEn] = $resolution;

            $optional = $this->resolveOptionalLocales($dict, $translations);
            $merged = $this->mergeNameTranslations($translations, $newIt, $newEn, $optional);

            if (! $force && $this->nameLocalesMatch($translations, $merged)) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $currentFr = $this->stringLocale($translations, 'fr');
                $currentDe = $this->stringLocale($translations, 'de');
                $currentEs = $this->stringLocale($translations, 'es');
                $this->line(sprintf(
                    '[dry-run] id=%d identifier=%s | it: %s -> %s | en: %s -> %s | fr: %s -> %s | de: %s -> %s | es: %s -> %s',
                    $row->id,
                    (string) $identifier,
                    $currentIt,
                    $newIt,
                    $currentEn,
                    $newEn,
                    $currentFr,
                    $optional['fr'],
                    $currentDe,
                    $optional['de'],
                    $currentEs,
                    $optional['es']
                ));
                $updated++;

                continue;
            }

            $row->setTranslations('name', $merged);
            $row->save();
            $updated++;
        }

        if ($missingInDict > 0) {
            $this->warn("Record senza voce nel dizionario (identifier non trovato): {$missingInDict}.");
        }
        if ($unresolvableLabels > 0) {
            $this->warn("Record con voce dizionario ma senza alcuna etichetta it o en (né in JSON né in DB): {$unresolvableLabels}.");
        }

        $this->info("Completato. Aggiornati: {$updated}, saltati: {$skipped}.");

        return self::SUCCESS;
    }

    private function resolveDictionaryPath(): ?string
    {
        $path = $this->option('dictionary');
        if (is_string($path) && $path !== '' && is_readable($path)) {
            return $path;
        }

        $fromEnv = env('TAXONOMY_POI_TYPES_DICTIONARY');
        if (is_string($fromEnv) && $fromEnv !== '' && is_readable($fromEnv)) {
            return $fromEnv;
        }

        $default = database_path(self::DEFAULT_DICTIONARY);
        if (is_readable($default)) {
            return $default;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $translations
     * @param  array{fr: string, de: string, es: string}  $optional
     * @return array<string, string>
     */
    private function mergeNameTranslations(array $translations, string $newIt, string $newEn, array $optional): array
    {
        $merged = $translations;
        $merged['it'] = $newIt;
        $merged['en'] = $newEn;
        foreach (self::OPTIONAL_LOCALES as $locale) {
            $merged[$locale] = $optional[$locale];
        }

        return $merged;
    }

    /**
     * @param  array{it: ?string, en: ?string, fr: ?string, de: ?string, es: ?string}  $dict
     * @param  array<string, mixed>  $translations
     * @return array{fr: string, de: string, es: string}
     */
    private function resolveOptionalLocales(array $dict, array $translations): array
    {
        $out = ['fr' => '', 'de' => '', 'es' => ''];
        foreach (self::OPTIONAL_LOCALES as $locale) {
            $dictVal = $dict[$locale] ?? null;
            $current = $this->stringLocale($translations, $locale);
            $out[$locale] = $this->firstNonEmptyString($dictVal, $current);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $translations
     */
    private function hasAllSyncedLocalesNonEmpty(array $translations): bool
    {
        foreach (self::SYNCED_LOCALES as $locale) {
            if (! $this->hasNonEmptyLocale($translations, $locale)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function nameLocalesMatch(array $a, array $b): bool
    {
        foreach (self::SYNCED_LOCALES as $locale) {
            if ($this->stringLocale($a, $locale) !== $this->stringLocale($b, $locale)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $translations
     */
    private function hasNonEmptyLocale(array $translations, string $locale): bool
    {
        $v = $translations[$locale] ?? null;

        return is_string($v) && trim($v) !== '';
    }

    /**
     * @param  array<string, mixed>  $translations
     */
    private function stringLocale(array $translations, string $locale): string
    {
        $v = $translations[$locale] ?? '';

        return is_string($v) ? trim($v) : '';
    }

    /**
     * @return array<string, array{it: ?string, en: ?string, fr: ?string, de: ?string, es: ?string}>
     */
    private function loadDictionary(string $path): array
    {
        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            $this->error('Il file dizionario non è un array JSON valido.');

            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = $item['identifier'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            $name = $item['name'] ?? [];
            $it = null;
            $en = null;
            $fr = null;
            $de = null;
            $es = null;
            if (is_array($name)) {
                $it = $this->trimmedNullableString($name['it'] ?? null);
                $en = $this->trimmedNullableString($name['en'] ?? null);
                $fr = $this->trimmedNullableString($name['fr'] ?? null);
                $de = $this->trimmedNullableString($name['de'] ?? null);
                $es = $this->trimmedNullableString($name['es'] ?? null);
            }
            $out[strtolower($id)] = ['it' => $it, 'en' => $en, 'fr' => $fr, 'de' => $de, 'es' => $es];
        }

        return $out;
    }

    private function trimmedNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $t = trim($value);

        return $t === '' ? null : $t;
    }

    /**
     * @param  array{it: ?string, en: ?string, fr: ?string, de: ?string, es: ?string}  $dict
     * @return array{0: string, 1: string}|null
     */
    private function resolveLabels(array $dict, string $currentIt, string $currentEn): ?array
    {
        $dictIt = $dict['it'] ?? null;
        $dictEn = $dict['en'] ?? null;

        $newIt = $this->firstNonEmptyString($dictIt, $currentIt, $dictEn, $currentEn);
        $newEn = $this->firstNonEmptyString($dictEn, $currentEn, $dictIt, $currentIt);

        if ($newIt === '' && $newEn === '') {
            return null;
        }

        if ($newIt === '') {
            $newIt = $newEn;
        }

        if ($newEn === '') {
            $newEn = $newIt;
        }

        return [$newIt, $newEn];
    }

    /** @param  string|null  ...$candidates */
    private function firstNonEmptyString(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }
}
