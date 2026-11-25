<?php

use Illuminate\Database\Migrations\Migration;
use Wm\WmPackage\TrailRegistry\Services\PrimaryActivityTaxonomyInstaller;

return new class extends Migration
{
    /**
     * Fa nascere la famiglia dell'attivita' primaria con il dominio (oc:9038).
     *
     * Sta qui e non in un comando perche' il requisito e' che **accendere
     * l'interruttore del Catasto e migrare basti**: le migration del dominio
     * sono gia' il passo obbligatorio dell'attivazione, un comando in piu'
     * sarebbe un passo che qualcuno dimentica, e chi lo dimentica si ritrova
     * l'attivita' primaria che si inserisce, si vede in Nova e non esce in API,
     * senza nessun errore.
     *
     * L'installer e' idempotente: cerca per identifier e non ricrea nulla.
     * Quale famiglia creare lo dice
     * `wm-package.features.trail_registry.primary_activity_taxonomy`, che ha un
     * default funzionante — uno shard che vuole un nome diverso lo imposta
     * **prima** di migrare.
     */
    public function up(): void
    {
        $install = fn () => app(PrimaryActivityTaxonomyInstaller::class)->install();

        // Telescope registra ogni query, e per decidere se farlo legge
        // `telescope_monitoring`. Se le sue migration non sono state pubblicate
        // — in CI capita, dove il database nasce vuoto a ogni run — quella
        // lettura fallisce, aborta la transazione della migration e
        // l'inserimento dei termini muore con un errore che di Telescope non
        // parla: «current transaction is aborted». Qui si sospende, se c'e'.
        if (class_exists(\Laravel\Telescope\Telescope::class)) {
            \Laravel\Telescope\Telescope::withoutRecording($install);

            return;
        }

        $install();
    }

    /**
     * Non si torna indietro: i termini potrebbero gia' avere eventi che li
     * referenziano, e le chiavi esterne della storia sono senza cascata
     * apposta. Un termine che esce di scena si marca ritirato, non si
     * cancella.
     */
    public function down(): void {}
};
