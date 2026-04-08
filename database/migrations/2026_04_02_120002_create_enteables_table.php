<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enteables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ente_id');
            $table->morphs('enteable');
            $table->string('ruolo');
            $table->unique(['ente_id', 'enteable_id', 'enteable_type', 'ruolo'], 'enteables_unique_ruolo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enteables');
    }
};
