<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Le avvertenze convergono nel modello unico delle classificazioni (oc:8910): diventano la
 * famiglia `warnings` di `taxonomy_terms`, e le loro due tabelle non servono più.
 *
 * I dati non si copiano: le tredici avvertenze e i loro legami vengono tutti da Drupal e li
 * riscrive `sardegnasentieri:import`, che su UAT gira comunque ogni mattina. Fra questa migration
 * e il primo import le avvertenze non ci sono.
 *
 * Niente `down()` che le ricrea: tornare indietro significa rimettere i due file di migration
 * cancellati insieme a questa, e rifare l'import.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Prima il legame: ha una colonna che punta alla tabella dei termini.
        Schema::dropIfExists('taxonomy_warningables');
        Schema::dropIfExists('taxonomy_warnings');
    }

    public function down(): void
    {
        //
    }
};
