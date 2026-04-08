<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entes', function (Blueprint $table) {
            $table->id();
            $table->string('sardegnasentieri_id')->unique();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->jsonb('contatti')->nullable();
            $table->string('pagina_web')->nullable();
            $table->string('tipo_ente')->nullable();
            $table->geography('geometry', 'pointz')->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entes');
    }
};
