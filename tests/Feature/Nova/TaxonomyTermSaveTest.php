<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Il salvataggio di una tassonomia e di un termine dall'endpoint vero di Nova (oc:8910).
 *
 * Esiste perché i test sul formato dell'identifier estraggono le `rules` del campo e le danno a
 * `Validator`: quella strada non costruisce la Resource come fa Nova, e un riferimento a una
 * costante che non esiste (`FAMILY_IDENTIFIER_FORMAT`, rimasta da quando le tassonomie si
 * chiamavano famiglie) passava i test e rompeva ogni salvataggio dalla scheda. Qui si fa la
 * richiesta HTTP che fa il browser: se la Resource non si costruisce, il test cade.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
    $this->user = User::factory()->create();
    $this->user->assignRole('Administrator');

    $this->taxonomy = TaxonomyTerm::create([
        'identifier' => 'hiking-difficulty',
        'name' => ['it' => 'Difficoltà escursionistica'],
        'icon' => 'txn-trekking',
    ]);
    $this->term = TaxonomyTerm::create([
        'parent_id' => $this->taxonomy->id,
        'identifier' => 'hiking-difficulty-t',
        'name' => ['it' => 'Turistico'],
    ]);
});

it('cambia l\'icona di una tassonomia dalla sua scheda', function () {
    $response = $this->actingAs($this->user)
        ->putJson("/nova-api/taxonomy-terms/{$this->taxonomy->id}", [
            'identifier' => 'hiking-difficulty',
            'icon' => 'txn-family',
            'sort_order' => 0,
            'translations_name_it' => 'Difficoltà escursionistica',
        ]);

    $response->assertOk();
    expect($this->taxonomy->fresh()->icon)->toBe('txn-family');
});

it('crea una tassonomia nuova senza passare dal codice', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/nova-api/taxonomy-terms', [
            'identifier' => 'surface-type',
            'icon' => 'txn-stone',
            'sort_order' => 0,
            'translations_name_it' => 'Tipo di fondo',
        ]);

    $response->assertCreated();
    expect(TaxonomyTerm::query()->where('identifier', 'surface-type')->exists())->toBeTrue();
});

it('rifiuta l\'identifier di una tassonomia con un punto, che Elasticsearch espanderebbe', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/nova-api/taxonomy-terms', [
            'identifier' => 'con.punto',
            'sort_order' => 0,
            'translations_name_it' => 'Con punto',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('identifier');
});

it('salva un termine: il formato dell\'identifier non vale per lui', function () {
    // Un termine porta l'identifier che gli dà la sorgente, punti compresi: è un valore, non una
    // chiave dell'indice.
    $response = $this->actingAs($this->user)
        ->putJson("/nova-api/taxonomy-terms/{$this->term->id}", [
            'parent' => $this->taxonomy->id,
            'identifier' => 'IT.A',
            'sort_order' => 3,
            'translations_name_it' => 'Turistico',
        ]);

    $response->assertOk();
    expect($this->term->fresh())
        ->identifier->toBe('IT.A')
        ->sort_order->toBe(3);
});
