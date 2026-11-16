<?php

use Illuminate\Database\Migrations\Migration;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityTaxonomyInstaller;

return new class extends Migration
{
    /**
     * Fa nascere la famiglia della percorribilita' con il dominio (oc:8989).
     *
     * Sta qui e non in un comando perche' il requisito e' che **accendere
     * l'interruttore del Catasto e migrare basti**: le migration del dominio
     * sono gia' il passo obbligatorio dell'attivazione, un comando in piu'
     * sarebbe un passo che qualcuno dimentica, e chi lo dimentica si ritrova
     * la percorribilita' che si inserisce, si vede in Nova e non esce in API,
     * senza nessun errore.
     *
     * L'installer e' idempotente: cerca per identifier e non ricrea nulla.
     * Quale famiglia creare lo dice
     * `wm-package.features.trail_registry.walkability_taxonomy`, che ha un
     * default funzionante — uno shard che vuole un nome diverso lo imposta
     * **prima** di migrare.
     */
    public function up(): void
    {
        app(WalkabilityTaxonomyInstaller::class)->install();
    }

    /**
     * Non si torna indietro: i termini potrebbero gia' avere eventi che li
     * referenziano, e le chiavi esterne della storia sono senza cascata
     * apposta. Un termine che esce di scena si marca ritirato, non si
     * cancella.
     */
    public function down(): void {}
};
