<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * I cambi di stato di un sentiero: percorribilita' oggi, validazione
     * domani (oc:8989). Non e' una tabella della percorribilita': `taxonomy_id`
     * dice di quale classificazione si parla, e la stessa riga serve ogni
     * famiglia a scelta singola.
     *
     * Niente riferimento polimorfico e niente unione con
     * `trail_registry_code_events`: un codice puo' esistere senza sentiero, i
     * suoi stati sono un enum con un CHECK e non termini di tassonomia, e
     * unendo le due storie entrambe perderebbero il proprio vincolo. La stessa
     * scelta e' gia' scritta nello stub dei codici.
     *
     * `from_term_id` e `to_term_id` sono chiavi esterne **senza cascata**: un
     * termine che ha storia non si cancella, si marca ritirato. E' voluto che
     * un comando che svuota `taxonomy_terms` fallisca invece di portarsi via la
     * cronologia in silenzio.
     */
    public function up(): void
    {
        if (Schema::hasTable('trail_status_events')) {
            return;
        }

        Schema::create('trail_status_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ec_track_id')->constrained('ec_tracks')->cascadeOnDelete();

            // La famiglia, non il suo identifier: l'identifier e' configurabile
            // dallo shard, l'id della riga no.
            $table->foreignId('taxonomy_id')->constrained('taxonomy_terms');

            // Nullo solo alla prima registrazione di un sentiero.
            $table->foreignId('from_term_id')->nullable()->constrained('taxonomy_terms');
            $table->foreignId('to_term_id')->constrained('taxonomy_terms');

            // La nota di chi registra: testo libero, come `issues_description`
            // su osm2cai. Non il `reason` da 64 caratteri degli eventi dei
            // codici, che e' una causale chiusa.
            $table->text('description')->nullable();

            // Vuoto in tutti i casi tranne l'inserimento a mano: chi ha scritto
            // l'evento lo dice `source`.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source', 16);

            $table->timestamp('created_at');

            // La query della cronologia: gli eventi di un sentiero per una
            // classificazione, dal piu' recente.
            $table->index(['ec_track_id', 'taxonomy_id', 'created_at'], 'trail_status_events_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trail_status_events');
    }
};
