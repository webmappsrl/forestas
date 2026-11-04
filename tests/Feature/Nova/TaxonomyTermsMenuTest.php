<?php

use App\Models\User;
use App\Nova\TaxonomyWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Nova\Menu\TaxonomyTermsMenu;
use Wm\WmPackage\Services\RolesAndPermissionsService;

uses(RefreshDatabase::class);

beforeEach(function () {
    RolesAndPermissionsService::seedDatabase();
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
});

it('elenca da sé le viste per tassonomia, senza che il menu le nomini', function () {
    $user = User::factory()->create();
    $user->assignRole('Administrator');
    // Nova registra le Resource solo quando serve il backoffice: qui lo si fa a mano.
    Nova::resourcesIn(app_path('Nova'));
    $request = NovaRequest::create('/nova', 'GET');
    $request->setUserResolver(fn () => $user);

    $items = TaxonomyTermsMenu::items($request);

    expect($items)->toHaveCount(1)
        ->and($items[0]->path)->toContain(TaxonomyWarning::uriKey());
});

it('intitola la voce con il nome italiano del tassonomia', function () {
    app()->setLocale('it');

    expect(TaxonomyWarning::label())->toBe('Avvertenze');
});

it('genera la Resource di un tassonomia con il solo identifier', function () {
    $path = app_path('Nova/Themes.php');
    @unlink($path);

    $this->artisan('wm:make-taxonomy-resource warnings --class=Themes')->assertSuccessful();

    $code = file_get_contents($path);
    expect($code)->toContain('class Themes extends AbstractTaxonomyTermsResource')
        ->and($code)->toContain("return 'warnings';")
        ->and($code)->not->toContain('uriKey');

    @unlink($path);
});

it('conserva un indirizzo già in uso quando glielo si chiede', function () {
    $path = app_path('Nova/ProvaAvvertenze.php');
    @unlink($path);

    $this->artisan('wm:make-taxonomy-resource warnings --class=ProvaAvvertenze --uri-key=taxonomy-warnings')
        ->assertSuccessful();

    expect(file_get_contents($path))->toContain("return 'taxonomy-warnings';");

    @unlink($path);
});

it('non sovrascrive una classe esistente senza --force', function () {
    $path = app_path('Nova/ProvaEsistente.php');
    file_put_contents($path, '<?php // mia');

    $this->artisan('wm:make-taxonomy-resource warnings --class=Themes --class=ProvaEsistente')->assertFailed();

    expect(file_get_contents($path))->toBe('<?php // mia');

    @unlink($path);
});

it('con una lingua senza traduzione ricade sul nome italiano, non su una voce vuota', function () {
    app()->setLocale('en');

    expect(TaxonomyWarning::label())->toBe('Avvertenze');
});

it('segue la traduzione del nome quando c\'è', function () {
    app()->setLocale('en');
    $taxonomy = TaxonomyTerm::where('identifier', 'warnings')->first();
    $taxonomy->setTranslation('name', 'en', 'Warnings')->save();

    expect(TaxonomyWarning::label())->toBe('Warnings');
});
