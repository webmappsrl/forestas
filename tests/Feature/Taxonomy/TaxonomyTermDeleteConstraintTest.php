<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Nessuna strada deve lasciare un termine senza la sua tassonomia (oc:8910).
 *
 * `taxonomy_terms` è auto-referenziale: una riga senza `parent_id` è una tassonomia. Con
 * `nullOnDelete` cancellare una tassonomia non cancellava i suoi termini, li promuoveva: i tre
 * termini di «Tipo percorso» diventavano tre tassonomie, e le loro relazioni restavano con la
 * copia di una tassonomia che non esisteva più — invisibili ai filtri, che raggruppano proprio
 * per quella copia. La stessa corruzione si otteneva senza cancellare niente, svuotando il campo
 * «Tassonomia» di un termine dalla sua scheda.
 */
uses(RefreshDatabase::class);

function taxonomyWithTerms(): array
{
    $suffix = uniqid();
    $taxonomy = TaxonomyTerm::create([
        'identifier' => "route-type-{$suffix}",
        'name' => ['it' => 'Tipo percorso'],
    ]);
    $trail = TaxonomyTerm::create([
        'parent_id' => $taxonomy->id,
        'identifier' => "route-type-trail-{$suffix}",
        'name' => ['it' => 'Sentiero'],
    ]);

    return [$taxonomy, $trail];
}


it('rifiuta di cancellare una tassonomia che ha ancora termini (oc:8910)', function () {
    [$taxonomy, $trail] = taxonomyWithTerms();

    // Dopo l'errore la transazione Postgres è abortita: non si può leggere nello stesso test,
    // e il fatto che il termine resti sotto la sua tassonomia è garantito dal rifiuto stesso.
    expect(fn () => $taxonomy->delete())->toThrow(QueryException::class);
});

it('lascia cancellare un termine foglia (oc:8910)', function () {
    [$taxonomy, $trail] = taxonomyWithTerms();

    $trail->delete();

    expect(TaxonomyTerm::whereKey($trail->id)->exists())->toBeFalse()
        ->and(TaxonomyTerm::whereKey($taxonomy->id)->exists())->toBeTrue();
});

it('lascia cancellare una tassonomia rimasta vuota (oc:8910)', function () {
    // È il caso di `ConfigureTaxonomyTermsCommand::removeRetired()`, che toglie le tassonomie
    // ritirate solo quando non hanno più né termini né relazioni.
    [$taxonomy, $trail] = taxonomyWithTerms();
    $trail->delete();

    $taxonomy->delete();

    expect(TaxonomyTerm::whereKey($taxonomy->id)->exists())->toBeFalse();
});

it('lascia svuotare la tabella in un colpo solo, come fa --fresh (oc:8910)', function () {
    // Il vincolo è NO ACTION e non `restrictOnDelete()`: il controllo è a fine statement,
    // quindi una cancellazione che porta via padri e figli insieme passa.
    taxonomyWithTerms();

    DB::table('taxonomy_terms')->delete();

    expect(TaxonomyTerm::count())->toBe(0);
});
