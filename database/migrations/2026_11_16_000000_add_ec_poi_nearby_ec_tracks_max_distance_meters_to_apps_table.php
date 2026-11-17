<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La distanza massima, in metri, entro cui una traccia è collegata a un POI (`ec_poi_ec_track`),
 * modificabile in Nova nella tab `pois` dell'App (oc:8959). Il default è il raggio usato finora.
 * Colonna di forestas su una tabella del package: nessun altro shard la legge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->integer('ec_poi_nearby_ec_tracks_max_distance_meters')->default(1000);
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('ec_poi_nearby_ec_tracks_max_distance_meters');
        });
    }
};
