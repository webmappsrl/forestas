<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le colonne di Forestas su `ec_tracks` (oc:8910).
 *
 * Tutte con il prefisso `forestas_`: se il package un giorno aggiunge una colonna omonima, il suo
 * ALTER TABLE non deve fallire sugli shard che se l'erano già creata.
 *
 * Codice e data di rilievo vengono da Drupal e li scrive l'import. Le tre dell'inclusività non le
 * possiede nessuna sorgente: si compilano a mano in Nova e l'import non le tocca.
 *
 * La zona geografica NON è qui: in Drupal è una lista di termini (13 sentieri ne hanno più di
 * una) e una colonna scalare li troncherebbe. Resta in `properties->forestas->zona_geografica`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_tracks', function (Blueprint $table) {
            $table->string('forestas_registry_code')->nullable()->index();
            $table->date('forestas_survey_date')->nullable();
            $table->smallInteger('forestas_inclusivity_physical')->nullable();
            $table->smallInteger('forestas_inclusivity_aids')->nullable();
            $table->smallInteger('forestas_inclusivity_digital')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ec_tracks', function (Blueprint $table) {
            $table->dropColumn([
                'forestas_registry_code',
                'forestas_survey_date',
                'forestas_inclusivity_physical',
                'forestas_inclusivity_aids',
                'forestas_inclusivity_digital',
            ]);
        });
    }
};
