<?php

namespace SocialDept\AtpParity\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SocialDept\AtpParity\Import\ImportService;

class ImportUserJob implements ShouldQueue
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
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 60;

    public function __construct(
        public string $did,
        public ?string $collection = null,
    ) {
        $this->onQueue(config('atp-parity.import.queue', 'default'));
    }

    public function handle(ImportService $service): void
    {
        $collections = $this->collection ? [$this->collection] : null;
        $service->importUser($this->did, $collections);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        $tags = ['parity-import', "did:{$this->did}"];

        if ($this->collection) {
            $tags[] = "collection:{$this->collection}";
        }

        return $tags;
    }
}
