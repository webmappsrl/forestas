<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trail_registry_code_events')) {
            return;
        }

        // Tabella in sola aggiunta: nessun update, nessuna cancellazione.
        // Serve perche' un numero puo' essere liberato e riassegnato piu'
        // volte, e tre colonne reserved_at/assigned_at/released_at
        // sovrascriverebbero il ciclo precedente.
        Schema::create('trail_registry_code_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trail_registry_code_id')
                ->constrained('trail_registry_codes')
                ->cascadeOnDelete();

            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);

            // Il perche': «liberato per deaccatastamento» e «liberato perche'
            // l'istanza e' stata respinta» sono due fatti diversi che nello
            // stato corrente si appiattirebbero entrambi in `released`.
            $table->string('reason', 64);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at');
        });

        DB::statement("
            ALTER TABLE trail_registry_code_events
            ADD CONSTRAINT trail_registry_code_events_to_status_check
            CHECK (to_status IN ('reserved', 'assigned', 'released'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('trail_registry_code_events');
    }
};
