<?php

namespace App\Services\RegistroCatastale;

/**
 * I tipi di anomalia scritti dal registro catastale (oc:8539) e la
 * provenienza con cui si distinguono da quelle del Catasto Sentieri.
 *
 * Le definizioni testuali (label, template) vivono nelle classi sotto
 * `AnomalyTypes/`, insieme alla registrazione in
 * `wm-package.features.trail_registry.anomaly_types`: qui ci sono solo le
 * costanti, che il matcher deve poter usare senza dipendere da quelle
 * classi.
 */
class RegistroAnomalyTypes
{
    /** Provenienza delle anomalie scritte dal registro, in `trail_registry_anomalies.source`. */
    public const SOURCE = 'registro';

    /** Il link della riga non porta a nessuna traccia, e il ripiego sul numero non basta o non c'e'. */
    public const LINK_ORFANO = 'registro_link_orfano';

    /** La traccia agganciata ha un codice attivo, ma numero o variante non coincidono col foglio. */
    public const NUMERO_DIVERSO = 'registro_numero_diverso';

    /** La traccia agganciata non ha un codice attivo, e non ha gia' un'anomalia del Catasto. */
    public const TRACCIA_SENZA_CODICE = 'registro_traccia_senza_codice';

    /** Il ripiego su area+settore+numero+variante trova piu' di un codice candidato. */
    public const RIPIEGO_AMBIGUO = 'registro_ripiego_ambiguo';
}
