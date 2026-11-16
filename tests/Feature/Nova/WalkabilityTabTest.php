<?php

declare(strict_types=1);

use App\Nova\EcTrack as EcTrackResource;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;
use Wm\WmPackage\TrailRegistry\Services\WalkabilityService;

/**
 * Il tab «Stato» della scheda di un sentiero (oc:8989): validazione, percorribilità e storico
 * nello stesso posto, e la percorribilità che si cambia solo passando dal service.
 */
function statusTabFields(EcTrack $track, User $user, string $method = 'GET', array $payload = []): array
{
    $request = NovaRequest::create('/nova-api/ec-tracks/'.$track->id, $method, $payload);
    $request->setUserResolver(fn () => $user);

    $fields = [];
    foreach ((new EcTrackResource($track))->fields($request) as $field) {
        if ($field instanceof TabsGroup) {
            foreach ($field->data as $candidate) {
                $fields[] = $candidate;
            }
        }
    }

    return $fields;
}

function statusTabAdmin(): User
{
    RolesAndPermissionsService::seedDatabase();
    $user = User::factory()->create();
    $user->assignRole('Administrator');

    return $user;
}

beforeEach(function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    $this->admin = statusTabAdmin();
    $this->track = EcTrack::factory()->createQuietly(['osmid' => null]);
});

it('non lascia il campo generico della percorribilità, che scriverebbe senza storia', function () {
    $attributes = array_map(fn ($field) => $field->attribute, statusTabFields($this->track, $this->admin));

    expect($attributes)->not->toContain('taxonomy_walkability_status')
        ->and($attributes)->toContain('walkability_status');
});

it('tiene validazione, percorribilità e storico nello stesso tab', function () {
    $tabs = [];
    foreach (statusTabFields($this->track, $this->admin) as $field) {
        $tab = $field->meta()['tab']['name'] ?? null;
        if (in_array($field->attribute, ['validation_status', 'walkability_status'], true)) {
            $tabs[$field->attribute] = $tab;
        }
    }

    expect($tabs['validation_status'])->toBe($tabs['walkability_status'])
        ->and($tabs['walkability_status'])->not->toBeNull();
});

it('salvando dal campo registra l\'evento con nota e autore', function () {
    $fields = statusTabFields($this->track, $this->admin, 'PUT', [
        'walkability_status' => 'walkability-status-not-walkable',
        'walkability_note' => 'ponte crollato',
    ]);

    $field = collect($fields)->firstWhere('attribute', 'walkability_status');
    $request = NovaRequest::create('/nova-api/ec-tracks/'.$this->track->id, 'PUT', [
        'walkability_status' => 'walkability-status-not-walkable',
        'walkability_note' => 'ponte crollato',
    ]);
    $request->setUserResolver(fn () => $this->admin);

    $callback = $field->fill($request, $this->track);
    expect($callback)->toBeCallable();
    $callback();

    $event = TrailStatusEvent::query()->where('ec_track_id', $this->track->id)->latest('id')->first();

    expect(app(WalkabilityService::class)->current($this->track->fresh())?->identifier)
        ->toBe('walkability-status-not-walkable')
        ->and($event->description)->toBe('ponte crollato')
        ->and($event->user_id)->toBe($this->admin->id);
});

it('senza uno stato scelto non scrive niente', function () {
    $fields = statusTabFields($this->track, $this->admin, 'PUT', ['walkability_status' => '']);
    $field = collect($fields)->firstWhere('attribute', 'walkability_status');

    $request = NovaRequest::create('/nova-api/ec-tracks/'.$this->track->id, 'PUT', ['walkability_status' => '']);
    $request->setUserResolver(fn () => $this->admin);

    expect($field->fill($request, $this->track))->not->toBeCallable()
        ->and(TrailStatusEvent::query()->where('ec_track_id', $this->track->id)->count())->toBe(0);
});
