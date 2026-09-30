<?php

namespace App\Services\RegistroCatastale;

/**
 * Legge lunghezza, tempi e testi del registro catastale (oc:8540). Il foglio
 * e' scritto a mano: ogni regola qui corrisponde a una variante che c'e'
 * davvero nel foglio, decisa una per una col dev. Cio' che nessuna regola
 * legge, o che chi ha compilato ha segnato come dubbio, e' illeggibile.
 *
 * Classe pura: niente DB, niente HTTP.
 */
class RegistroValueSanitizer
{
    /** Velocita' a piedi plausibile, in km/h, per decidere se un intero sono ore o minuti. */
    private const MIN_SPEED = 1.0;

    private const MAX_SPEED = 6.0;

    /**
     * «lunghezza (m)»: intero = metri; punto seguito da 3 cifre = migliaia;
     * separatore seguito da 1 o 2 cifre = decimale in km (2,6 m non e' un
     * sentiero).
     */
    public function lengthKm(string $raw): RegistroSanitizedValue
    {
        $value = trim($raw);

        if ($value === '') {
            return RegistroSanitizedValue::empty();
        }

        if (preg_match('/^\d+$/', $value)) {
            return RegistroSanitizedValue::of(round(((int) $value) / 1000, 3));
        }

        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            return RegistroSanitizedValue::of(round(((int) str_replace('.', '', $value)) / 1000, 3));
        }

        if (preg_match('/^(\d+)[.,](\d{1,2})$/', $value, $m)) {
            return RegistroSanitizedValue::of(round((float) "{$m[1]}.{$m[2]}", 3));
        }

        return RegistroSanitizedValue::invalid();
    }

    /**
     * «T. percorrenza»: `h:mm` in minuti. `$lengthKm` serve solo a decidere
     * un intero senza unita'.
     */
    public function durationMinutes(string $raw, ?float $lengthKm): RegistroSanitizedValue
    {
        $value = trim($raw);

        if ($value === '') {
            return RegistroSanitizedValue::empty();
        }

        // Chi compila segna cosi' un valore di cui non e' sicuro.
        if (str_contains($value, '?')) {
            return RegistroSanitizedValue::invalid();
        }

        // `O1:30`: lettera O al posto dello zero, solo accanto a una cifra.
        $value = preg_replace('/O(?=\d)|(?<=\d)O/', '0', $value);

        if (preg_match("/^(\d+)'$/", $value, $m)) {
            return RegistroSanitizedValue::of((int) $m[1]);
        }

        if (preg_match('/^\d+$/', $value)) {
            return $this->integerByWalkingSpeed((int) $value, $lengthKm);
        }

        if (! preg_match('/^(\d{1,2})\s*(?::\.|-\.|:|\.|-)\s*(\d{2})(?!\d)(.*)$/s', $value, $m)) {
            return RegistroSanitizedValue::invalid();
        }

        // Il testo dopo l'orario si scarta (`antiorario`), ma se contiene un
        // secondo orario (`cartello … dice 1:30`) la cella non e' univoca.
        // Un separatore seguito da una cifra subito dopo i minuti (`1:30:45`)
        // e' un secondo orario o dei secondi: illeggibile.
        if (preg_match('/^[:.]\d/', $m[3]) || preg_match('/\d{1,2}\s*[:.]\s*\d{2}/', $m[3])) {
            return RegistroSanitizedValue::invalid();
        }

        // I minuti sono da 0 a 59.
        if ((int) $m[2] > 59) {
            return RegistroSanitizedValue::invalid();
        }

        return RegistroSanitizedValue::of(((int) $m[1]) * 60 + (int) $m[2]);
    }

    /** D, E, F: una riga sola, spazi compressi, vuoto = null. */
    public function text(string $raw): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $raw));

        return $value === '' ? null : $value;
    }

    /**
     * Un intero senza unita': vale la lettura (ore o minuti) che con la
     * lunghezza della riga da' una velocita' a piedi plausibile. Se lo sono
     * entrambe, nessuna, o manca la lunghezza, non si decide.
     */
    private function integerByWalkingSpeed(int $n, ?float $lengthKm): RegistroSanitizedValue
    {
        if ($n === 0 || $lengthKm === null || $lengthKm <= 0) {
            return RegistroSanitizedValue::invalid();
        }

        $plausible = fn (float $hours) => ($speed = $lengthKm / $hours) >= self::MIN_SPEED && $speed <= self::MAX_SPEED;

        $asHours = $plausible($n);
        $asMinutes = $plausible($n / 60);

        if ($asHours === $asMinutes) {
            return RegistroSanitizedValue::invalid();
        }

        return RegistroSanitizedValue::of($asHours ? $n * 60 : $n);
    }
}
