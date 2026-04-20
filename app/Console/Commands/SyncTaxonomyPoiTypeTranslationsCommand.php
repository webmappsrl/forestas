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
                            {--force : Aggiorna anche i record che hanno già sia "it" che "en" valorizzati}';

    protected $description = 'Aggiorna taxonomy_poi_types.name (it/en) dal dizionario JSON per identifier, garantendo entrambe le lingue (fallback dizionario + valori già in DB).';

    /** @var array<string, array{it: ?string, en: ?string}> */
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
            if (! $force && $this->hasNonEmptyLocale($translations, 'it') && $this->hasNonEmptyLocale($translations, 'en')) {
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

            $merged = $this->mergeNameTranslations($translations, $newIt, $newEn);

            if (! $force && $this->nameLocalesMatch($translations, $merged)) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] id=%d identifier=%s | it: %s -> %s | en: %s -> %s',
                    $row->id,
                    (string) $identifier,
                    $currentIt,
                    $newIt,
                    $currentEn,
                    $newEn
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
            $this->warn("Record con voce dizionario ma senza alcuna etichetta it/en (né in JSON né in DB): {$unresolvableLabels}.");
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
     * @return array<string, string>
     */
    private function mergeNameTranslations(array $translations, string $newIt, string $newEn): array
    {
        $merged = $translations;
        $merged['it'] = $newIt;
        $merged['en'] = $newEn;

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function nameLocalesMatch(array $a, array $b): bool
    {
        return $this->stringLocale($a, 'it') === $this->stringLocale($b, 'it')
            && $this->stringLocale($a, 'en') === $this->stringLocale($b, 'en');
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
     * @return array<string, array{it: ?string, en: ?string}>
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
            if (is_array($name)) {
                $it = isset($name['it']) && is_string($name['it']) ? trim($name['it']) : null;
                $en = isset($name['en']) && is_string($name['en']) ? trim($name['en']) : null;
                if ($it === '') {
                    $it = null;
                }
                if ($en === '') {
                    $en = null;
                }
            }
            $out[strtolower($id)] = ['it' => $it, 'en' => $en];
        }

        return $out;
    }

    /**
     * @param  array{it: ?string, en: ?string}  $dict
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
