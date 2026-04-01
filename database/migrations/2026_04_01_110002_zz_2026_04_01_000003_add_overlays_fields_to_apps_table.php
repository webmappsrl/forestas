<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->jsonb('config_overlays')->nullable()->after('config_home');
            $table->jsonb('overlays_label')->nullable()->after('config_overlays');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['config_overlays', 'overlays_label']);
        });
    }
};
