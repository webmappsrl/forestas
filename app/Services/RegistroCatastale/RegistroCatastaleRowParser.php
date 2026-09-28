<?php

namespace App\Services\RegistroCatastale;

/**
 * Trasforma un RegistroCatastaleSheet grezzo in righe tipizzate
 * (oc:8539): riconosce i fogli di registro dalle intestazioni chiave,
 * scarta le righe di servizio e ricava area/settore/numero/variante dai
 * formati osservati sul foglio reale.
 */
class RegistroCatastaleRowParser
{
    private const KEY_HEADERS = [
        'area' => 'codprovinciaearea',
        'sector' => 'settore',
        'number' => 'numero',
        'link' => 'linksardegnasentieri',
    ];

    /**
     * Un foglio e' un registro se ha tutte le colonne chiave, con qualsiasi
     * grafia (spazi, accenti, maiuscole non contano).
     */
    public function isRegistro(RegistroCatastaleSheet $sheet): bool
    {
        $found = $this->keyColumns($sheet->rows[0] ?? []);

        return count(array_filter($found, fn (array $positions) => $positions !== [])) === count(self::KEY_HEADERS);
    }

    /**
     * @return list<RegistroCatastaleParsedRow>
     */
    public function parse(RegistroCatastaleSheet $sheet): array
    {
        $headers = $sheet->rows[0] ?? [];
        $columns = $this->keyColumns($headers);

        foreach ($columns as $key => $positions) {
            if (count($positions) > 1) {
                throw new RegistroCatastaleReadException("Il foglio «{$sheet->name}» ha due colonne «{$key}»: non si sceglie a caso quale leggere.");
            }
        }

        $result = [];
        $lastArea = null;

        foreach (array_slice($sheet->rows, 1) as $index => $values) {
            $cell = fn (string $key) => trim($values[$columns[$key][0]] ?? '');

            // Si eredita l'area della riga sopra solo se la cella e' VUOTA: una
            // cella scritta ma illeggibile o ambigua (es. `Z-SU-D Z-CA-C`) resta
            // senza area, altrimenti le si attribuirebbe quella di un'altra riga.
            $rawArea = $cell('area');
            $area = $rawArea === '' ? $lastArea : $this->areaLetter($rawArea);
            $lastArea = $area;
            [$sector, $number, $variant] = $this->number($cell('number'));
            $link = $cell('link');

            if ($number === null && $link === '') {
                continue;
            }

            $result[] = new RegistroCatastaleParsedRow(
                gid: $sheet->gid,
                sheetName: $sheet->name,
                rowNumber: $index + 2,
                cells: array_map(fn ($header, $i) => ['header' => (string) $header, 'value' => (string) ($values[$i] ?? '')], $headers, array_keys($headers)),
                link: $link,
                area: $area,
                sector: $sector ?? $this->fallbackSector($cell('sector')),
                number: $number,
                variant: $variant,
            );
        }

        return $result;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, list<int>>
     */
    private function keyColumns(array $headers): array
    {
        $normalized = array_map(fn ($h) => preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $h)), $headers);

        return array_map(
            fn (string $wanted) => array_keys(array_filter($normalized, fn ($h) => $h === $wanted)),
            self::KEY_HEADERS,
        );
    }

    /**
     * `Z-NU-B` -> `B`; `NU-G` -> `G`; `Z-SU-D Z-CA-D` -> `D` (la provincia non conta).
     */
    private function areaLetter(string $raw): ?string
    {
        preg_match_all('/[A-Z]{2}-([A-Z])\b/', strtoupper($raw), $m);
        $letters = array_unique($m[1]);

        return count($letters) === 1 ? $letters[0] : null;
    }

    /**
     * Quando la cella Numero non si legge, la colonna SETTORE del foglio e'
     * testo libero (es. «vedi mappa»), non un settore affidabile: si accetta
     * solo se e' proprio una singola cifra, altrimenti si lascia il settore
     * `null` invece di scriverci dentro una nota.
     */
    private function fallbackSector(string $raw): ?string
    {
        return preg_match('/^\d$/', $raw) === 1 ? $raw : null;
    }

    /**
     * `162` -> settore 1, numero 62; `163A`, `182 A`, `302/A` -> variante A.
     * Note e punteggiatura dopo il numero si scartano: la lettera catturata
     * come variante e' valida solo se non e' seguita da un'altra lettera o
     * da un punto (altrimenti e' l'inizio di una nota, come in `210S.I.`).
     *
     * @return array{0: ?string, 1: ?int, 2: string}
     */
    private function number(string $raw): array
    {
        if (! preg_match('/^\s*(?:[A-Z]-)?(\d)(\d{2})\s*[\/-]?\s*(?:([A-Za-z])(?![A-Za-z.]))?/', $raw, $m)) {
            return [null, null, '0'];
        }

        return [$m[1], (int) $m[2], isset($m[3]) ? strtoupper($m[3]) : '0'];
    }
}
