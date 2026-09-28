<?php

namespace App\Services\RegistroCatastale;

use RuntimeException;

/**
 * Errore durante la lettura del registro catastale dal foglio Google
 * (oc:8539): pagina htmlview senza fogli elencati, o un foglio che non
 * restituisce un CSV (tipicamente perche' la condivisione via link e'
 * stata revocata).
 */
class RegistroCatastaleReadException extends RuntimeException {}
