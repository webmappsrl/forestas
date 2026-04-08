<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_warnings', function (Blueprint $table) {
            $table->id();
            $table->text('name');
            $table->text('description')->nullable();
            $table->string('excerpt')->nullable();
            $table->text('identifier')->nullable()->unique();
            $table->timestamps();
            $table->jsonb('properties')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_warnings');
    }
};
