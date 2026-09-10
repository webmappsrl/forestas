<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trail_applications')) {
            return;
        }

        Schema::create('trail_applications', function (Blueprint $table) {
            $table->id();

            // Chi ha inserito l'istanza nel Catasto: il client API quando
            // arriva da API, l'utente autenticato quando nasce d'ufficio.
            // Non e' il proponente (che non ha un account qui) e non e'
            // l'operatore che istruisce.
            $table->foreignId('user_id')->constrained('users');

            // Come e' arrivata la domanda. Mai il nome dello sportello:
            // questo codice servira' anche Lombardia e Toscana.
            $table->string('source', 16);

            $table->string('status', 32)->index();

            $table->text('name')->nullable();
            $table->jsonb('properties')->nullable();

            // geography, non geometry: stesso tipo di ec_tracks.geometry
            // (create_ec_tracks_table.php.stub) e taxonomy_wheres.geometry.
            // Quando un'istanza viene approvata (Task 10) la sua geometria
            // viene copiata sul nuovo EcTrack con un UPDATE diretto — con
            // tipi disallineati quella copia fallirebbe o perderebbe la
            // coordinata Z.
            $table->geography('geometry', 'multiLineStringz')->nullable();

            $table->timestamps();
        });

        DB::statement('
            CREATE INDEX IF NOT EXISTS trail_applications_geometry_gist
            ON trail_applications USING GIST (geometry)
        ');

        DB::statement("
            ALTER TABLE trail_applications
            ADD CONSTRAINT trail_applications_source_check
            CHECK (source IN ('api', 'office'))
        ");

        DB::statement("
            ALTER TABLE trail_applications
            ADD CONSTRAINT trail_applications_status_check
            CHECK (status IN ('under_review', 'rejected', 'approved'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('trail_applications');
    }
};
