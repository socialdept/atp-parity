<?php

namespace SocialDept\AtpParity\PendingSync;

use SocialDept\AtpParity\Contracts\PendingSyncStore;

class DatabasePendingSyncStore implements PendingSyncStore
{
    /**
     * Store a pending sync operation.
     */
    public function store(PendingSync $pendingSync): void
    {
        PendingSyncState::create([
            'pending_id' => $pendingSync->id,
            'did' => $pendingSync->did,
            'model_class' => $pendingSync->modelClass,
            'model_id' => $pendingSync->modelId,
            'operation' => $pendingSync->operation->value,
            'reference_mapper_class' => $pendingSync->referenceMapperClass,
            'attempts' => $pendingSync->attempts,
        ]);
    }

    /**
     * Get all pending syncs for a DID.
     *
     * @return array<PendingSync>
     */
    public function forDid(string $did): array
    {
        return PendingSyncState::forDid($did)
            ->get()
            ->map(fn (PendingSyncState $state) => $state->toPendingSync())
            ->all();
    }

    /**
     * Get a pending sync by ID.
     */
    public function find(string $id): ?PendingSync
    {
        $state = PendingSyncState::where('pending_id', $id)->first();

        return $state?->toPendingSync();
    }

    /**
     * Update a pending sync (e.g., increment attempts).
     */
    public function update(PendingSync $pendingSync): void
    {
        PendingSyncState::where('pending_id', $pendingSync->id)
            ->update([
                'attempts' => $pendingSync->attempts,
            ]);
    }

    /**
     * Remove a pending sync by ID.
     */
    public function remove(string $id): void
    {
        PendingSyncState::where('pending_id', $id)->delete();
    }

    /**
     * Remove all pending syncs for a DID.
     */
    public function removeForDid(string $did): int
    {
        return PendingSyncState::forDid($did)->delete();
    }

    /**
     * Remove all pending syncs for a specific model.
     */
    public function removeForModel(string $modelClass, int|string $modelId): int
    {
        return PendingSyncState::forModel($modelClass, $modelId)->delete();
    }

    /**
     * Remove expired pending syncs.
     */
    public function removeExpired(): int
    {
        return PendingSyncState::expired()->delete();
    }

    /**
     * Count pending syncs for a DID.
     */
    public function countForDid(string $did): int
    {
        return PendingSyncState::forDid($did)->count();
    }

    /**
     * Check if any pending syncs exist for a DID.
     */
    public function hasForDid(string $did): bool
    {
        return PendingSyncState::forDid($did)->exists();
    }
}
