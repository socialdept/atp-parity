<?php

namespace SocialDept\AtpParity\Contracts;

use SocialDept\AtpParity\PendingSync\PendingSync;

interface PendingSyncStore
{
    /**
     * Store a pending sync operation.
     */
    public function store(PendingSync $pendingSync): void;

    /**
     * Get all pending syncs for a DID.
     *
     * @return array<PendingSync>
     */
    public function forDid(string $did): array;

    /**
     * Get a pending sync by ID.
     */
    public function find(string $id): ?PendingSync;

    /**
     * Update a pending sync (e.g., increment attempts).
     */
    public function update(PendingSync $pendingSync): void;

    /**
     * Remove a pending sync by ID.
     */
    public function remove(string $id): void;

    /**
     * Remove all pending syncs for a DID.
     *
     * @return int Number of items removed
     */
    public function removeForDid(string $did): int;

    /**
     * Remove all pending syncs for a specific model.
     *
     * @return int Number of items removed
     */
    public function removeForModel(string $modelClass, int|string $modelId): int;

    /**
     * Remove expired pending syncs (older than TTL).
     *
     * @return int Number of items removed
     */
    public function removeExpired(): int;

    /**
     * Count pending syncs for a DID.
     */
    public function countForDid(string $did): int;

    /**
     * Check if any pending syncs exist for a DID.
     */
    public function hasForDid(string $did): bool;
}
