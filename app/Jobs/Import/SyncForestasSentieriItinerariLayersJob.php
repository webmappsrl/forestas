<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

class SyncForestasSentieriItinerariLayersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Mappa type API -> definizione layer (nome UI + chiave canonica).
     *
     * @var array<string, array{name: string, key: string}>
     */
    private const TYPE_MAP = [
        'sentiero' => ['name' => 'Sentieri', 'key' => 'sentiero'],
        'itinerario' => ['name' => 'Itinerari', 'key' => 'itinerario'],
    ];

    public function __construct(
        public readonly int $appId = 1
    ) {}

    public function handle(): void
    {
        $layersByKey = [];
        foreach (self::TYPE_MAP as $def) {
            $key = $def['key'];
            if (! isset($layersByKey[$key])) {
                $layersByKey[$key] = $this->findOrCreateLayerForKey($key, $def['name']);
            }
        }

        $ecTrackModelClass = config('wm-package.ec_track_model', \Wm\WmPackage\Models\EcTrack::class);

        $tracks = $ecTrackModelClass::query()
            ->where('app_id', $this->appId)
            ->whereRaw("(properties->'forestas'->>'type') IS NOT NULL")
            ->get();

        foreach ($tracks as $track) {
            $type = data_get($track->properties, 'forestas.type');
            if (! is_string($type)) {
                continue;
            }

            $apiType = Str::lower(trim($type));
            $def = self::TYPE_MAP[$apiType] ?? null;
            $key = is_array($def) ? ($def['key'] ?? null) : null;

            $layer = is_string($key) ? ($layersByKey[$key] ?? null) : null;
            if (! $layer instanceof Layer) {
                continue;
            }

            $track->layers()->syncWithoutDetaching([$layer->id]);
        }
    }

    private function findOrCreateLayerForKey(string $key, string $name): Layer
    {
        $existing = Layer::query()
            ->where('app_id', $this->appId)
            ->whereRaw("properties->>'source' = ?", ['forestas'])
            ->where(function ($q) use ($key) {
                $q->whereRaw("properties->>'key' = ?", [$key])
                    ->orWhereRaw("properties->>'type' = ?", [$key]);
            })
            ->first();

        if ($existing instanceof Layer) {
            return $existing;
        }

        $appUserId = App::query()->find($this->appId)?->user_id;

        return Layer::query()->create([
            'app_id' => $this->appId,
            'user_id' => $appUserId,
            'name' => ['it' => $name, 'en' => $name],
            'properties' => [
                'name' => ['it' => $name, 'en' => $name],
            ],
            'configuration' => [],
        ]);
    }
}

