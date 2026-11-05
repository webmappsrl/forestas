<?php

use Illuminate\Support\Facades\Artisan;

/**
 * `sardegnasentieri:reset` è stato tolto (oc:8910).
 *
 * Cancellava tracce e POI senza ripulire `taxonomy_termables`, poi azzerava le sequence: le
 * tracce reimportate rinascevano con gli stessi id ed ereditavano le classificazioni delle
 * cancellate — l'accessibilità compilata a mano finiva sul sentiero sbagliato. Aveva altri due
 * difetti della stessa natura (filtrava i pivot con il nome della classe invece che con l'alias
 * del morph, lasciava i media dei POI) e non arrivava comunque in fondo, per la chiave esterna
 * del catasto. L'azzeramento è `sardegnasentieri:import --reset`, che tronca in CASCADE e
 * `taxonomy_termables` la pulisce.
 */
it('non esiste più fra i comandi registrati (oc:8910)', function () {
    expect(array_keys(Artisan::all()))->not->toContain('sardegnasentieri:reset');
});

it('il file del comando non è più nel progetto (oc:8910)', function () {
    // Non `class_exists()`: la classmap di Composer tiene il vecchio percorso finché non si
    // rigenera l'autoload, e tentare di caricarlo solleva un errore invece di tornare false.
    expect(file_exists(base_path('app/Console/Commands/ResetSardegnaSentieriCommand.php')))->toBeFalse();
});

it('l_azzeramento dell_import pulisce le relazioni dei termini (oc:8910)', function () {
    // È la riga che al comando tolto mancava: senza, le classificazioni sopravvivono alle
    // tracce e con le sequence azzerate finiscono addosso alle nuove.
    $source = file_get_contents(base_path('app/Console/Commands/ImportSardegnaSentieriCommand.php'));

    expect($source)->toContain("DB::table('taxonomy_termables')")
        ->and($source)->toContain('TRACK_AND_POI_MORPH_TYPES');
});
