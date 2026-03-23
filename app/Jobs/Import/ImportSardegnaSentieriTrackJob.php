<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Dto\Import\SardegnaSentieriImageManifest;
use App\Http\Clients\SardegnaSentieriClient;
use App\Services\Import\SardegnaSentieriImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportSardegnaSentieriTrackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     * GPX download can be slow, so we allow more time.
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $externalId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SardegnaSentieriClient $client, SardegnaSentieriImportService $service): void
    {
        $response = $client->getTrackDetail($this->externalId);
        $track = $service->importTrackFromResponse($this->externalId, $response);

        ImportSardegnaSentieriTrackMediaJob::dispatch(
            $track->id,
            SardegnaSentieriImageManifest::fromApiTrackResponse($response)
        );
    }
}
