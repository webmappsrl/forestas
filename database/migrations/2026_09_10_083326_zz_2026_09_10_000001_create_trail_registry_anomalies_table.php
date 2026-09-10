<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            // sparisce, la sua anomalia non ha piu' nulla da dire.
            $table->foreignId('ec_track_id')
                ->constrained('ec_tracks')
                ->cascadeOnDelete();

            $table->string('type', 32);

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
        });

        // Nessun vincolo di unicita' sull'ec_track_id: un sentiero puo' avere
        // piu' anomalie insieme (il caso reale e' il settore discordante che
        // finisce anche in conflitto), e sono correzioni diverse.
        DB::statement("
            ALTER TABLE trail_registry_anomalies
            ADD CONSTRAINT trail_registry_anomalies_type_check
            CHECK (type IN (
                'codice_gia_assegnato',
                'settore_discordante',
                'geometria_duplicata',
                'codice_illeggibile',
                'fuori_da_ogni_settore'
            ))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('trail_registry_anomalies');
    }
};
