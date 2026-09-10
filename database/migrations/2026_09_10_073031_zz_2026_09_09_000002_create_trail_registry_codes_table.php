<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trail_registry_codes')) {
            return;
        }

        Schema::create('trail_registry_codes', function (Blueprint $table) {
            $table->id();

            // Le quattro lettere del full_code restano colonne separate:
            // rendono interrogabile lo spazio dei codici senza confronti su
            // stringa, e congelano il codice emesso anche se il settore
            // cambia in un reimport di OSM2CAI.
            $table->char('region', 1);
            $table->string('province', 2)->index();
            $table->char('area', 1);
            $table->char('sector', 1);

            // Intero, due cifre: e' cio' che rende realizzabile la
            // contiguita' numerica con una riga di SQL.
            $table->unsignedSmallInteger('number');

            // Mai NULL: due NULL non collidono e l'indice unico non
            // proteggerebbe proprio nel caso piu' frequente.
            $table->char('variant', 1)->default('0');

            $table->string('status', 16)->index();

            // Come il codice e' nato: letto da un campo dedicato, estratto
            // dal nome, o proposto dalla piattaforma. Non e' un dettaglio
            // storico: dice quanto fidarsi di ogni riga.
            $table->string('origin', 16)->default('campo_dedicato');

            // Da dove e' stato ricavato il prefisso: permette di accorgersi
            // se quel settore cambia o viene rimosso da un reimport.
            $table->foreignId('taxonomy_where_id')->nullable()
                ->constrained('taxonomy_wheres')->nullOnDelete();

            // Due colonne distinte e non un riferimento polimorfico: si
            // conservano entrambi i legami, e sono chiavi esterne vere.
            $table->foreignId('trail_application_id')->nullable()
                ->constrained('trail_applications');
            $table->foreignId('ec_track_id')->nullable()
                ->constrained('ec_tracks');

            $table->timestamps();
        });

        // Forma delle colonne: il database dichiara cosa e' rappresentabile,
        // il codice PHP cosa e' ammesso oggi. Su variant il margine 1-9 e'
        // deliberato (sottosentieri del modello CAI, oggi fuori scope): se un
        // domani vanno ammessi, si allenta il controllo applicativo senza una
        // migration su tabella popolata.
        DB::statement("
            ALTER TABLE trail_registry_codes
            ADD CONSTRAINT trail_registry_codes_number_check
            CHECK (number BETWEEN 0 AND 99)
        ");

        DB::statement("
            ALTER TABLE trail_registry_codes
            ADD CONSTRAINT trail_registry_codes_variant_check
            CHECK (variant ~ '^[0-9A-Z]$')
        ");

        DB::statement("
            ALTER TABLE trail_registry_codes
            ADD CONSTRAINT trail_registry_codes_region_check
            CHECK (region ~ '^[A-Z]$')
        ");

        // I tre valori devono coincidere con TrailCodeStatus::cases().
        // Se un giorno si aggiunge/rimuove uno stato, questo CHECK e la
        // clausola WHERE dell'indice unico parziale piu' sotto vanno
        // aggiornati insieme.
        DB::statement("
            ALTER TABLE trail_registry_codes
            ADD CONSTRAINT trail_registry_codes_status_check
            CHECK (status IN ('reserved', 'assigned', 'released'))
        ");

        // I tre valori devono coincidere con TrailCodeOrigin::cases().
        DB::statement("
            ALTER TABLE trail_registry_codes
            ADD CONSTRAINT trail_registry_codes_origin_check
            CHECK (origin IN ('campo_dedicato', 'nome', 'assegnato'))
        ");

        // Coerenza fra stato e riferimenti. Ogni prenotazione nasce da
        // un'istanza, anche quella fatta d'ufficio, quindi non esiste il caso
        // «riservato senza istanza».
        DB::statement("
            ALTER TABLE trail_registry_codes
            ADD CONSTRAINT trail_registry_codes_holder_check
            CHECK (
                (status = 'reserved' AND trail_application_id IS NOT NULL AND ec_track_id IS NULL)
                OR (status = 'assigned' AND ec_track_id IS NOT NULL)
                OR (status = 'released')
            )
        ");

        // L'indice e' PARZIALE: una riga liberata resta in tabella con i suoi
        // riferimenti, altrimenti un numero liberato non sarebbe piu'
        // riassegnabile a nessuno. La clausola WHERE deve coincidere con
        // TrailCodeStatus::active() — si cambiano insieme al CHECK sullo
        // stato sopra.
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS trail_registry_codes_active_unique
            ON trail_registry_codes (region, province, area, sector, number, variant)
            WHERE status IN ('reserved', 'assigned')
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS trail_registry_codes_active_unique');
        Schema::dropIfExists('trail_registry_codes');
    }
};
