<?php

namespace SocialDept\AtpParity\Discovery;

use BackedEnum;
use Generator;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\Import\ImportService;
use Throwable;

/**
 * Service for discovering DIDs with records in specific collections.
 */
class DiscoveryService
{
    public function __construct(
        protected ImportService $importService
    ) {}

    /**
     * Discover all DIDs with records in a collection.
     *
     * @return Generator<string> Yields DIDs
     */
    public function discoverDids(string|BackedEnum $collection, ?int $limit = null): Generator
    {
        $collection = $collection instanceof BackedEnum ? $collection->value : $collection;
        $cursor = null;
        $count = 0;

        do {
            $response = Atp::public()->atproto->sync->listReposByCollection(
                collection: $collection,
                limit: min(500, $limit ? $limit - $count : 500),
                cursor: $cursor,
            );

            foreach ($response->repos as $repo) {
                $did = $repo['did'] ?? null;

                if ($did) {
                    yield $did;
                    $count++;

                    if ($limit !== null && $count >= $limit) {
                        return;
                    }
                }
            }

            $cursor = $response->cursor;
        } while ($cursor !== null);
    }

    /**
     * Discover DIDs and return as an array.
     */
    public function discover(string|BackedEnum $collection, ?int $limit = null): DiscoveryResult
    {
        try {
            $dids = iterator_to_array($this->discoverDids($collection, $limit));
            $incomplete = $limit !== null && count($dids) >= $limit;

            return DiscoveryResult::success($dids, $incomplete);
        } catch (Throwable $e) {
            return DiscoveryResult::failed($e->getMessage());
        }
    }

    /**
     * Discover and import all users for a collection.
     */
    public function discoverAndImport(
        string|BackedEnum $collection,
        ?int $limit = null,
        ?callable $onProgress = null
    ): DiscoveryResult {
        $collection = $collection instanceof BackedEnum ? $collection->value : $collection;

        try {
            $dids = [];
            $count = 0;

            foreach ($this->discoverDids($collection, $limit) as $did) {
                $dids[] = $did;
                $count++;

                // Start import for this DID
                $this->importService->import($did, [$collection]);

                if ($onProgress) {
                    $onProgress($did, $count);
                }
            }

            $incomplete = $limit !== null && count($dids) >= $limit;

            return DiscoveryResult::success($dids, $incomplete);
        } catch (Throwable $e) {
            return DiscoveryResult::failed($e->getMessage());
        }
    }

    /**
     * Count total DIDs with records in a collection.
     *
     * Note: This iterates through all results, which can be slow.
     */
    public function count(string|BackedEnum $collection): int
    {
        $count = 0;

        foreach ($this->discoverDids($collection) as $_) {
            $count++;
        }

        return $count;
    }
}
