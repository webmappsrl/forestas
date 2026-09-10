<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('taxonomy_wheres')) {
            return;
        }

        // Requisito, non ottimizzazione: durante la pianificazione una query
        // ST_Intersects fra tracce e taxonomy_wheres ha richiesto oltre due
        // minuti sul database di sviluppo. In prevalidazione resolveSector()
        // deve rispondere entro il tempo di una chiamata API.
        DB::statement('
            CREATE INDEX IF NOT EXISTS taxonomy_wheres_geometry_gist
            ON taxonomy_wheres USING GIST (geometry)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS taxonomy_wheres_geometry_gist');
    }
};
