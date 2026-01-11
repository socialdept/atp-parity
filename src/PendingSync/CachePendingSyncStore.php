<?php

namespace SocialDept\AtpParity\PendingSync;

use Illuminate\Contracts\Cache\Repository;
use SocialDept\AtpParity\Contracts\PendingSyncStore;

class CachePendingSyncStore implements PendingSyncStore
{
    protected string $prefix = 'parity:pending_syncs:';

    public function __construct(
        protected Repository $cache,
        protected int $ttl = 3600,
    ) {}

    /**
     * Store a pending sync operation.
     */
    public function store(PendingSync $pendingSync): void
    {
        // Store by ID
        $this->cache->put(
            $this->idKey($pendingSync->id),
            $pendingSync->toArray(),
            $this->ttl
        );

        // Add to DID index
        $this->addToIndex($this->didKey($pendingSync->did), $pendingSync->id);

        // Add to model index
        $this->addToIndex(
            $this->modelKey($pendingSync->modelClass, $pendingSync->modelId),
            $pendingSync->id
        );
    }

    /**
     * Get all pending syncs for a DID.
     *
     * @return array<PendingSync>
     */
    public function forDid(string $did): array
    {
        return $this->getFromIndex($this->didKey($did));
    }

    /**
     * Get a pending sync by ID.
     */
    public function find(string $id): ?PendingSync
    {
        $data = $this->cache->get($this->idKey($id));

        return $data ? PendingSync::fromArray($data) : null;
    }

    /**
     * Update a pending sync (e.g., increment attempts).
     */
    public function update(PendingSync $pendingSync): void
    {
        $this->cache->put(
            $this->idKey($pendingSync->id),
            $pendingSync->toArray(),
            $this->ttl
        );
    }

    /**
     * Remove a pending sync by ID.
     */
    public function remove(string $id): void
    {
        $pendingSync = $this->find($id);

        if (! $pendingSync) {
            return;
        }

        // Remove from ID storage
        $this->cache->forget($this->idKey($id));

        // Remove from DID index
        $this->removeFromIndex($this->didKey($pendingSync->did), $id);

        // Remove from model index
        $this->removeFromIndex(
            $this->modelKey($pendingSync->modelClass, $pendingSync->modelId),
            $id
        );
    }

    /**
     * Remove all pending syncs for a DID.
     */
    public function removeForDid(string $did): int
    {
        $pending = $this->forDid($did);
        $count = count($pending);

        foreach ($pending as $item) {
            $this->remove($item->id);
        }

        return $count;
    }

    /**
     * Remove all pending syncs for a specific model.
     */
    public function removeForModel(string $modelClass, int|string $modelId): int
    {
        $pending = $this->getFromIndex($this->modelKey($modelClass, $modelId));
        $count = count($pending);

        foreach ($pending as $item) {
            $this->remove($item->id);
        }

        return $count;
    }

    /**
     * Remove expired pending syncs.
     *
     * Note: Cache entries auto-expire via TTL, but this method allows
     * explicit cleanup of any stale index entries.
     */
    public function removeExpired(): int
    {
        // Cache entries auto-expire, so this is a no-op for cache storage.
        // Index entries may have stale references, but they'll fail gracefully
        // when the actual pending sync is not found.
        return 0;
    }

    /**
     * Count pending syncs for a DID.
     */
    public function countForDid(string $did): int
    {
        $ids = $this->cache->get($this->didKey($did), []);

        return count($ids);
    }

    /**
     * Check if any pending syncs exist for a DID.
     */
    public function hasForDid(string $did): bool
    {
        return $this->countForDid($did) > 0;
    }

    /**
     * Get pending syncs from an index key.
     *
     * @return array<PendingSync>
     */
    protected function getFromIndex(string $key): array
    {
        $ids = $this->cache->get($key, []);
        $results = [];

        foreach (array_keys($ids) as $id) {
            $pendingSync = $this->find($id);

            if ($pendingSync) {
                $results[] = $pendingSync;
            }
        }

        return $results;
    }

    /**
     * Add an ID to an index.
     */
    protected function addToIndex(string $key, string $id): void
    {
        $ids = $this->cache->get($key, []);
        $ids[$id] = true;
        $this->cache->put($key, $ids, $this->ttl);
    }

    /**
     * Remove an ID from an index.
     */
    protected function removeFromIndex(string $key, string $id): void
    {
        $ids = $this->cache->get($key, []);
        unset($ids[$id]);

        if (empty($ids)) {
            $this->cache->forget($key);
        } else {
            $this->cache->put($key, $ids, $this->ttl);
        }
    }

    protected function idKey(string $id): string
    {
        return $this->prefix.'id:'.$id;
    }

    protected function didKey(string $did): string
    {
        return $this->prefix.'did:'.$did;
    }

    protected function modelKey(string $modelClass, int|string $modelId): string
    {
        return $this->prefix.'model:'.$modelClass.':'.$modelId;
    }
}
