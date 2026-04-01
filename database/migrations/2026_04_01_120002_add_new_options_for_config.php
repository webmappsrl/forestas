<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->boolean('show_travel_mode')->default(false);
            $table->boolean('show_features_in_viewport')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('show_travel_mode');
            $table->dropColumn('show_features_in_viewport');
        });
    }
};
