<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trail_registry_anomalies')) {
            return;
        }

        // La lista di lavoro del gestore: cosa non va, su quale sentiero, e
        // possibilmente cosa scrivere per sistemarlo. Si riscrive da zero a
        // ogni esecuzione del comando di normalizzazione — nessuno storico,
        // quindi nessun updated_at.
        Schema::create('trail_registry_anomalies', function (Blueprint $table) {
            $table->id();

            // Chiave esterna vera con cancellazione a cascata: se il sentiero
            // sparisce, la sua anomalia non ha piu' nulla da dire. Nullable
            // perche' non tutte le fonti hanno sempre un ec_track_id (es. un
            // segnalatore esterno che riferisce solo il codice conteso).
            $table->foreignId('ec_track_id')
                ->nullable()
                ->constrained('ec_tracks')
                ->cascadeOnDelete();

            // I tipi sono validati in applicazione dal registro dei tipi
            // (TrailRegistryAnomalyTypes), non da un vincolo CHECK: piu' fonti
            // possono dichiarare tipi propri senza richiedere una migration.
            $table->string('type', 32);

            // Chi ha scritto l'anomalia (es. "catasto"): sempre dichiarato da
            // chi scrive, mai da un default — cosi' non si confonde con le
            // anomalie del catasto e non viene ripulito dal normalize.
            $table->string('source', 32);

            // Il contendente: chi tiene la posizione contesa, o l'altra
            // traccia con la stessa geometria.
            $table->foreignId('related_ec_track_id')
                ->nullable()
                ->constrained('ec_tracks')
                ->cascadeOnDelete();

            // I DATI dell'anomalia, non la frase che la descrive: il codice
            // conteso, chi lo tiene, i gemelli, il codice che la piattaforma
            // assegnerebbe. La frase si compone in lettura, con un template
            // per tipo (AnomalyDetailRenderer) — cosi' correggere una parola
            // non richiede di rigenerare le righe, e quelle gia' in tabella
            // si aggiornano da se'.
            $table->jsonb('context')->nullable();

            $table->timestamp('created_at');

            $table->index('type');
            $table->index('ec_track_id');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trail_registry_anomalies');
    }
};
