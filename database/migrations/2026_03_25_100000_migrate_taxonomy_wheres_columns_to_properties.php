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

        if (Schema::hasColumn('taxonomy_wheres', 'osmfeatures_id')) {
            DB::statement("
                UPDATE taxonomy_wheres
                SET properties = COALESCE(properties, '{}')::jsonb
                    || jsonb_strip_nulls(jsonb_build_object(
                        'osmfeatures_id', osmfeatures_id,
                        'admin_level',    admin_level,
                        'source',        'osmfeatures'
                    ))
                WHERE osmfeatures_id IS NOT NULL
            ");

            Schema::table('taxonomy_wheres', function ($table) {
                $table->dropUnique(['osmfeatures_id']);
                $table->dropColumn(['osmfeatures_id', 'admin_level']);
            });
        }

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_taxonomy_wheres_osmfeatures_id
            ON taxonomy_wheres ((properties->>'osmfeatures_id'))
            WHERE properties->>'osmfeatures_id' IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('taxonomy_wheres', function ($table) {
            $table->string('osmfeatures_id')->unique()->nullable();
            $table->integer('admin_level')->nullable();
        });
    }
};
