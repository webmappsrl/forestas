<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('taxonomy_whereables', function (Blueprint $table) {
            $table->dropForeign('taxonomy_whereables_taxonomy_where_id_foreign');
            $table->foreign('taxonomy_where_id')
                ->references('id')
                ->on('taxonomy_wheres')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('taxonomy_whereables', function (Blueprint $table) {
            $table->dropForeign('taxonomy_whereables_taxonomy_where_id_foreign');
            $table->foreign('taxonomy_where_id')
                ->references('id')
                ->on('taxonomy_wheres');
        });
    }
};
