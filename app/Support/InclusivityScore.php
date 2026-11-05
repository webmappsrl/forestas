<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EcTrack;

/**
 * Punteggio di inclusività e classe A1-A6 di un sentiero (oc:8910).
 *
 * Non è memorizzato: si calcola dalle tre colonne `forestas_inclusivity_*` e si deposita
 * nell'indice Elasticsearch. Le soglie delle classi stanno in `config/forestas.php`.
 */
class InclusivityScore
{
    /**
     * @return array{score: int, class: string}|null null se manca anche uno solo dei tre valori:
     *                                               un sentiero con il solo AF compilato non è in classe bassa, è non misurato.
     */
    public static function for(EcTrack $track): ?array
    {
        $parts = [
            $track->forestas_inclusivity_physical,
            $track->forestas_inclusivity_aids,
            $track->forestas_inclusivity_digital,
        ];

        foreach ($parts as $part) {
            if ($part === null) {
                return null;
            }
        }

        $score = (int) array_sum($parts);
        $class = self::classFor($score);

        return $class === null ? null : ['score' => $score, 'class' => $class];
    }

    public static function classFor(int $score): ?string
    {
        foreach (config('forestas.inclusivity_classes', []) as $class => $range) {
            if ($score >= $range['min'] && $score <= $range['max']) {
                return (string) $class;
            }
        }

        return null;
    }
}
