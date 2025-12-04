<?php

namespace SocialDept\AtpParity\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpParity\Discovery\DiscoveryService;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

class DiscoverCommand extends Command
{
    protected $signature = 'parity:discover
        {collection : The collection NSID to discover (e.g., app.bsky.feed.post)}
        {--limit= : Maximum number of DIDs to discover}
        {--import : Import records for discovered DIDs}
        {--output= : Output DIDs to file (one per line)}
        {--count : Only count DIDs without listing them}';

    protected $description = 'Discover DIDs with records in a specific collection';

    public function handle(DiscoveryService $service): int
    {
        $collection = $this->argument('collection');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if ($this->option('count')) {
            return $this->handleCount($service, $collection);
        }

        if ($this->option('import')) {
            return $this->handleDiscoverAndImport($service, $collection, $limit);
        }

        return $this->handleDiscover($service, $collection, $limit);
    }

    protected function handleCount(DiscoveryService $service, string $collection): int
    {
        info("Counting DIDs with records in {$collection}...");

        $count = $service->count($collection);

        info("Found {$count} DIDs");

        return self::SUCCESS;
    }

    protected function handleDiscover(DiscoveryService $service, string $collection, ?int $limit): int
    {
        $limitDisplay = $limit ? " (limit: {$limit})" : '';
        info("Discovering DIDs with records in {$collection}{$limitDisplay}...");

        $result = $service->discover($collection, $limit);

        if ($result->isFailed()) {
            error("Discovery failed: {$result->error}");

            return self::FAILURE;
        }

        if ($result->total === 0) {
            note('No DIDs found');

            return self::SUCCESS;
        }

        // Output to file if requested
        if ($output = $this->option('output')) {
            file_put_contents($output, implode("\n", $result->dids)."\n");
            info("Found {$result->total} DIDs, written to {$output}");

            if ($result->isIncomplete()) {
                note('Results may be incomplete due to limit');
            }

            return self::SUCCESS;
        }

        // Output to console
        foreach ($result->dids as $did) {
            $this->line($did);
        }

        info("Found {$result->total} DIDs");

        if ($result->isIncomplete()) {
            note('Results may be incomplete due to limit');
        }

        return self::SUCCESS;
    }

    protected function handleDiscoverAndImport(DiscoveryService $service, string $collection, ?int $limit): int
    {
        $limitDisplay = $limit ? " (limit: {$limit})" : '';
        info("Discovering and importing DIDs with records in {$collection}{$limitDisplay}...");

        $result = $service->discoverAndImport(
            $collection,
            $limit,
            function (string $did, int $count) {
                note("[{$count}] Importing {$did}");
            }
        );

        if ($result->isFailed()) {
            error("Discovery failed: {$result->error}");

            return self::FAILURE;
        }

        info("Imported records for {$result->total} DIDs");

        if ($result->isIncomplete()) {
            note('Results may be incomplete due to limit');
        }

        return self::SUCCESS;
    }
}
