<?php

declare(strict_types=1);

use App\Nova\EcTrack as EcTrackResource;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * I campi dell'avviso di un sentiero (oc:8981), restituiti come coppie attributo => tab.
 * Per i campi dentro `NovaTabTranslatable` il tab è quello del contenitore.
 *
 * @return array<int, array{attribute: string, tab: string|null, name: string}>
 */
function noticeTabFieldList(EcTrack $track, User $user): array
{
    $request = NovaRequest::create('/nova-api/ec-tracks/'.$track->id, 'GET');
    $request->setUserResolver(fn () => $user);

    $list = [];
    foreach ((new EcTrackResource($track))->fields($request) as $field) {
        if (! $field instanceof TabsGroup) {
            continue;
        }
        foreach ($field->data as $candidate) {
            $tab = $candidate->meta()['tab']['name'] ?? null;
            $inner = property_exists($candidate, 'originalFields') ? $candidate->originalFields : [$candidate];
            foreach ($inner as $f) {
                $list[] = ['attribute' => (string) $f->attribute, 'tab' => $tab, 'name' => (string) $f->name];
            }
        }
    }

    return $list;
}

function noticeTabIndex(array $list, string $attribute): int|false
{
    return array_search($attribute, array_column($list, 'attribute'), true);
}

function noticeAttributes(): array
{
    return [
        'properties->not_accessible',
        'properties->not_accessible_message',
        'properties->not_accessible_updated_at',
    ];
}

beforeEach(function () {
    $this->artisan('sardegnasentieri:configure-taxonomy-terms');
    RolesAndPermissionsService::seedDatabase();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');
    $this->track = EcTrack::factory()->createQuietly(['osmid' => null]);
});

it('moves the notice fields out of the Info tab', function () {
    $list = noticeTabFieldList($this->track, $this->admin);

    foreach (noticeAttributes() as $attribute) {
        $found = array_values(array_filter($list, fn ($f) => $f['attribute'] === $attribute));
        expect($found)->not->toBeEmpty();
        foreach ($found as $f) {
            expect($f['tab'])->not->toBe('Info');
        }
    }
});

it('puts the notice after walkability and before history in the Status tab', function () {
    $list = noticeTabFieldList($this->track, $this->admin);

    $notice = noticeTabIndex($list, 'properties->not_accessible');
    $note = noticeTabIndex($list, 'walkability_note');

    expect($notice)->not->toBeFalse()
        ->and($note)->not->toBeFalse()
        ->and($notice)->toBeGreaterThan($note);

    // Lo storico è il primo campo con nome vuoto dopo l'avviso.
    $history = null;
    foreach ($list as $i => $f) {
        if ($i > $notice && trim($f['name']) === '' && $f['tab'] === 'Status') {
            $history = $i;
            break;
        }
    }
    expect($history)->not->toBeNull()
        ->and($notice)->toBeLessThan($history);
});

it('keeps the notice fields with the trail registry disabled', function () {
    config(['wm-package.features.trail_registry.enabled' => false]);

    $list = noticeTabFieldList($this->track, $this->admin);

    foreach (noticeAttributes() as $attribute) {
        $found = array_values(array_filter($list, fn ($f) => $f['attribute'] === $attribute));
        expect($found)->not->toBeEmpty();
        foreach ($found as $f) {
            expect($f['tab'])->toBe('Status');
        }
    }
});

it('labels the fields as notice', function () {
    $list = noticeTabFieldList($this->track, $this->admin);
    $names = array_column($list, 'name', 'attribute');

    expect($names['properties->not_accessible'])->toBe(__('Publish notice'))
        ->and($names['properties->not_accessible_message'])->toBe(__('Notice message'));
});

it('keeps the notice fields out of the index', function () {
    $request = NovaRequest::create('/nova-api/ec-tracks/'.$this->track->id, 'GET');
    $request->setUserResolver(fn () => $this->admin);

    $shownOnIndex = [];
    foreach ((new EcTrackResource($this->track))->fields($request) as $field) {
        if (! $field instanceof TabsGroup) {
            continue;
        }
        foreach ($field->data as $candidate) {
            $inner = property_exists($candidate, 'originalFields') ? $candidate->originalFields : [$candidate];
            foreach ($inner as $f) {
                if (in_array((string) $f->attribute, noticeAttributes(), true)) {
                    // Conta anche il contenitore `NovaTabTranslatable`: nell'index si vedrebbe
                    // la sua etichetta, anche se il campo che contiene è nascosto.
                    if ($f->showOnIndex || $candidate->showOnIndex) {
                        $shownOnIndex[] = (string) $f->attribute;
                    }
                }
            }
        }
    }

    expect($shownOnIndex)->toBe([]);
});
