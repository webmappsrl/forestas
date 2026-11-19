<?php

declare(strict_types=1);

namespace App\Services\TrailRegistry;

use App\Services\TrailRegistry\InconsistencyTypes\MissingWalkability;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\Cards\TrailRegistryInconsistenciesCard;

/**
 * Ricalcola le incongruenze catastali della percorribilita' (oc:8989).
 *
 * Oggi ce n'e' una sola: un sentiero che il catasto dichiara validato o
 * certificato, e di cui nessuno sa se si cammina.
 *
 * **Quando gira.** Alla fine dell'import, dove si ricalcolano anche le
 * anomalie, e ogni volta che la percorribilita' cambia: a differenza delle
 * anomalie, questa dipende anche da un dato che l'import non tocca, inserito a
 * mano da Nova. Se girasse solo a fine import, una percorribilita' registrata
 * alle dieci resterebbe segnalata come mancante fino al giro successivo.
 *
 * Il ricalcolo e' completo e idempotente: toglie le righe che non valgono piu'
 * e scrive quelle che mancano, cosi' due giri di fila non lasciano doppioni.
 */
class WalkabilityInconsistencyService
{
    /** Gli stati della pratica che presuppongono di aver percorso il sentiero. */
    private const VALIDATED_STATES = [
        'validation-status-validated',
        'validation-status-certified',
    ];

    private const UNKNOWN_WALKABILITY = 'walkability-status-unknown';

    public function recalculate(): void
    {
        $affected = $this->tracksWithValidatedButUnknownWalkability();

        TrailRegistryAnomaly::query()
            ->where('source', TrailRegistryInconsistenciesCard::SOURCE)
            ->where('type', MissingWalkability::TYPE)
            ->whereNotIn('ec_track_id', $affected === [] ? [0] : $affected)
            ->delete();

        $already = TrailRegistryAnomaly::query()
            ->where('source', TrailRegistryInconsistenciesCard::SOURCE)
            ->where('type', MissingWalkability::TYPE)
            ->pluck('ec_track_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach (array_diff($affected, $already) as $trackId) {
            TrailRegistryAnomaly::create([
                'ec_track_id' => $trackId,
                'type' => MissingWalkability::TYPE,
                'source' => TrailRegistryInconsistenciesCard::SOURCE,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * I sentieri validati o certificati la cui percorribilita' e' sconosciuta,
     * o che non ne hanno affatto.
     *
     * @return list<int>
     */
    private function tracksWithValidatedButUnknownWalkability(): array
    {
        $validatedIds = TaxonomyTerm::query()
            ->whereIn('identifier', self::VALIDATED_STATES)
            ->pluck('id');

        if ($validatedIds->isEmpty()) {
            return [];
        }

        $knownWalkabilityIds = TaxonomyTerm::query()
            ->whereIn('identifier', $this->knownWalkabilityIdentifiers())
            ->pluck('id');

        $validated = DB::table('taxonomy_termables')
            ->where('termable_type', 'like', '%EcTrack')
            ->whereIn('taxonomy_term_id', $validatedIds)
            ->pluck('termable_id');

        $withKnownWalkability = DB::table('taxonomy_termables')
            ->where('termable_type', 'like', '%EcTrack')
            ->whereIn('taxonomy_term_id', $knownWalkabilityIds)
            ->pluck('termable_id');

        return array_values(array_map(
            fn ($id) => (int) $id,
            array_diff($validated->all(), $withKnownWalkability->all()),
        ));
    }

    /**
     * Gli stati di percorribilita' che contano come «si sa»: tutti tranne
     * quello che dice che non si sa. Si leggono dal database, cosi' uno stato
     * aggiunto domani entra senza toccare questo codice.
     *
     * @return list<string>
     */
    private function knownWalkabilityIdentifiers(): array
    {
        /** @var string $familyIdentifier */
        $familyIdentifier = config('wm-package.features.trail_registry.walkability_taxonomy', 'walkability-status');

        $family = TaxonomyTerm::query()->where('identifier', $familyIdentifier)->whereNull('parent_id')->first();

        if ($family === null) {
            return [];
        }

        return $family->children()
            ->where('identifier', '!=', self::UNKNOWN_WALKABILITY)
            ->pluck('identifier')
            ->all();
    }
}
