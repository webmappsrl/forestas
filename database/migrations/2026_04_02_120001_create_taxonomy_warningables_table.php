<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_warningables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('taxonomy_warning_id');
            $table->morphs('taxonomy_warningable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_warningables');
    }
};
