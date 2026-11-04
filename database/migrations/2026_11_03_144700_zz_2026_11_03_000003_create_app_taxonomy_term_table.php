<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quali tassonomie sono attivi in quale app (oc:8910). Una riga vuol dire acceso:
        // non c'è uno stato "spento" da scrivere, si toglie la riga. Ci vanno solo i
        // tassonomie (le righe senza parent_id): i termini seguono il loro tassonomia.
        if (! Schema::hasTable('app_taxonomy_term')) {
            Schema::create('app_taxonomy_term', function (Blueprint $table) {
                $table->id();
                $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
                $table->foreignId('taxonomy_term_id')->constrained('taxonomy_terms')->cascadeOnDelete();

                $table->unique(['app_id', 'taxonomy_term_id'], 'app_taxonomy_term_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_taxonomy_term');
    }
};
