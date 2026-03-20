<?php

declare(strict_types=1);

namespace App\Jobs\Import;

use App\Services\Import\SardegnaSentieriPoiImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportSardegnaSentieriPoiJob implements ShouldQueue
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
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly int $externalId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(SardegnaSentieriPoiImportService $service): void
    {
        $service->importPoi($this->externalId);
    }
}
