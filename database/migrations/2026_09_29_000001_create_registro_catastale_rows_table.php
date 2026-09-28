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
        Schema::create('registro_catastale_rows', function (Blueprint $table) {
            $table->id();
            $table->string('sheet_gid', 32);
            $table->string('sheet_name');
            $table->unsignedInteger('row_number');
            // Elenco ordinato di {header, value}: un foglio può ripetere
            // un'intestazione (Gallura, «Comuni di appartenenza»), e una mappa
            // perderebbe un valore.
            $table->jsonb('cells');
            $table->text('link');
            // Solo le righe consistenti hanno il codice. La FK porta la riga via con
            // il reset notturno, insieme ai codici: si ricostruiscono nella stessa
            // catena (oc:8539).
            $table->foreignId('trail_registry_code_id')->nullable()->constrained('trail_registry_codes')->cascadeOnDelete();
            $table->timestamp('imported_at');

            $table->unique(['sheet_gid', 'row_number']);
            $table->index('trail_registry_code_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registro_catastale_rows');
    }
};
