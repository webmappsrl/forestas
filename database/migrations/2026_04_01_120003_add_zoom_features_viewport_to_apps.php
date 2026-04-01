<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->integer('min_zoom_features_in_viewport')->default(10);
            $table->integer('max_zoom_features_in_viewport')->default(12);
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('min_zoom_features_in_viewport');
            $table->dropColumn('max_zoom_features_in_viewport');
        });
    }
};
