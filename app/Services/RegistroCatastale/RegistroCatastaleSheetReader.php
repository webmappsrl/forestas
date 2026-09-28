<?php

namespace App\Services\RegistroCatastale;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Scarica tutti i fogli del file Google del registro catastale (oc:8539):
 * il registro vero e proprio e i fogli di legenda. Solo download qui: il
 * parsing e il match con i codici del Catasto sono nei task successivi.
 */
class RegistroCatastaleSheetReader
{
    /** @return list<RegistroCatastaleSheet> */
    public function readAll(): array
    {
        $base = $this->baseUrl();
        $sheets = [];

        foreach ($this->sheetIndex($base) as $gid => $name) {
            $response = $this->get("{$base}/export", ['format' => 'csv', 'gid' => $gid], "il foglio «{$name}» (gid {$gid})");

            if (! $response->successful()) {
                throw new RegistroCatastaleReadException("Il foglio «{$name}» (gid {$gid}) ha risposto {$response->status()}.");
            }

            if (! Str::contains((string) $response->header('Content-Type'), 'text/csv')) {
                throw new RegistroCatastaleReadException("Il foglio «{$name}» (gid {$gid}) non è un CSV: il file è ancora condiviso con chi ha il link?");
            }

            $sheets[] = new RegistroCatastaleSheet((string) $gid, $name, $this->parseCsv($response->body()));
        }

        return $sheets;
    }

    /**
     * @return array<string, string> gid => nome del foglio
     */
    private function sheetIndex(string $base): array
    {
        $response = $this->get("{$base}/htmlview", [], 'la pagina htmlview');

        if (! $response->successful()) {
            throw new RegistroCatastaleReadException("La pagina htmlview ha risposto {$response->status()}: il file è ancora condiviso con chi ha il link?");
        }

        $html = $response->body();

        // Non e' un'interfaccia documentata da Google: la pagina htmlview non
        // ha elementi statici per i fogli, l'elenco e' dentro uno <script>
        // come array JS `items.push({name: "...", pageUrl: "...", gid: "..."})`.
        // Se Google cambia questo formato, qui non si trova nulla e l'import
        // si ferma senza toccare il mirror.
        preg_match_all(
            '/items\.push\(\{name:\s*"((?:[^"\\\\]|\\\\.)*)",\s*pageUrl:\s*"(?:[^"\\\\]|\\\\.)*",\s*gid:\s*"(\d+)"/',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        if ($matches === []) {
            throw new RegistroCatastaleReadException('La pagina htmlview non elenca nessun foglio.');
        }

        return collect($matches)->mapWithKeys(fn (array $m) => [$m[2] => $this->unescapeJsString($m[1])])->all();
    }

    /**
     * Una GET con timeout e un ritentativo. Qualunque guasto di rete — host
     * irraggiungibile, timeout, risposta 5xx anche dopo il ritentativo —
     * diventa una RegistroCatastaleReadException: e' l'unica che job e
     * comando intercettano per scrivere una riga sul canale `import` senza
     * stack trace, lasciando intatto il mirror del giro precedente.
     *
     * Con `throw: false` una risposta di errore torna al chiamante, che la
     * giudica col proprio messaggio; resta da intercettare solo l'eccezione
     * di connessione, che il client lancia comunque.
     *
     * @param  array<string, string|int>  $query
     */
    private function get(string $url, array $query, string $what): Response
    {
        try {
            return Http::timeout(60)->retry(2, 2000, throw: false)->get($url, $query);
        } catch (ConnectionException|RequestException $e) {
            throw new RegistroCatastaleReadException("Impossibile scaricare {$what}: {$e->getMessage()}", previous: $e);
        }
    }

    private function baseUrl(): string
    {
        $url = (string) config('forestas.registro_catastale.url');

        if (! preg_match('#^(https://docs\.google\.com/spreadsheets/d/[A-Za-z0-9_-]+)#', $url, $m)) {
            throw new RegistroCatastaleReadException('forestas.registro_catastale.url non è l\'URL di un foglio Google.');
        }

        return $m[1];
    }

    /**
     * Decodifica una stringa JS come quelle nell'array `items.push(...)`
     * della pagina htmlview: escape `\/`, `\"` e `\xHH`.
     */
    private function unescapeJsString(string $value): string
    {
        $value = str_replace(['\\/', '\\"'], ['/', '"'], $value);

        return preg_replace_callback(
            '/\\\\x([0-9A-Fa-f]{2})/',
            fn (array $m) => chr((int) hexdec($m[1])),
            $value
        );
    }

    /** @return list<list<string>> */
    private function parseCsv(string $body): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $body);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = array_map(fn ($cell) => (string) $cell, $row);
        }
        fclose($handle);

        return $rows;
    }
}
