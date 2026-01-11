<?php

namespace SocialDept\AtpParity\Concerns;

use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;
use SocialDept\AtpParity\Sync\ReferenceSyncResult;
use SocialDept\AtpParity\Sync\ReferenceSyncService;
use SocialDept\AtpParity\Sync\SyncResult;

/**
 * Trait providing manual sync methods for models with reference records.
 *
 * Use this trait when you want explicit control over when syncing happens.
 * For automatic syncing on model events, use AutoSyncsWithReference instead.
 *
 * This trait requires getReferenceMapper() method to be available.
 * Use with either HasReferenceRecord or ReferencesAtpRecord trait.
 *
 * @method \SocialDept\AtpParity\Contracts\ReferenceMapper|null getReferenceMapper()
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait SyncsWithReference
{
    /**
     * Sync both main and reference records together.
     *
     * Automatically detects the DID from the model.
     */
    public function syncWithReference(): ReferenceSyncResult
    {
        $did = $this->syncAsDid();

        if (! $did) {
            return ReferenceSyncResult::failed('No DID associated with model.');
        }

        return $this->syncWithReferenceAs($did);
    }

    /**
     * Sync both main and reference records as a specific DID.
     */
    public function syncWithReferenceAs(string $did): ReferenceSyncResult
    {
        $mapper = $this->getReferenceMapper();

        if (! $mapper) {
            return ReferenceSyncResult::failed('No reference mapper registered for model: '.static::class);
        }

        return app(ReferenceSyncService::class)->syncWithReference($did, $this, $mapper);
    }

    /**
     * Sync only the reference record (main record must already exist).
     */
    public function syncReferenceOnly(): SyncResult
    {
        $did = $this->syncAsDid();

        if (! $did) {
            return SyncResult::failed('No DID associated with model.');
        }

        return $this->syncReferenceOnlyAs($did);
    }

    /**
     * Sync only the reference record as a specific DID.
     */
    public function syncReferenceOnlyAs(string $did): SyncResult
    {
        $mapper = $this->getReferenceMapper();

        if (! $mapper) {
            return SyncResult::failed('No reference mapper registered for model: '.static::class);
        }

        return app(ReferenceSyncService::class)->syncReferenceOnly($did, $this, $mapper);
    }

    /**
     * Sync reference record pointing to an external main record.
     *
     * Use this when the main record exists elsewhere (e.g., third-party platform)
     * and you want to create a reference pointing to it.
     */
    public function syncReferenceToExternal(StrongRef $mainRef): SyncResult
    {
        $did = $this->syncAsDid();

        if (! $did) {
            return SyncResult::failed('No DID associated with model.');
        }

        return $this->syncReferenceToExternalAs($did, $mainRef);
    }

    /**
     * Sync reference record to external main record as a specific DID.
     */
    public function syncReferenceToExternalAs(string $did, StrongRef $mainRef): SyncResult
    {
        $mapper = $this->getReferenceMapper();

        if (! $mapper) {
            return SyncResult::failed('No reference mapper registered for model: '.static::class);
        }

        return app(ReferenceSyncService::class)->syncReferenceToExternal($did, $this, $mapper, $mainRef);
    }

    /**
     * Resync the reference record.
     */
    public function resyncReference(): SyncResult
    {
        $mapper = $this->getReferenceMapper();

        if (! $mapper) {
            return SyncResult::failed('No reference mapper registered for model: '.static::class);
        }

        return app(ReferenceSyncService::class)->resyncReference($this, $mapper);
    }

    /**
     * Resync both main and reference records.
     */
    public function resyncWithReference(): ReferenceSyncResult
    {
        $mapper = $this->getReferenceMapper();

        if (! $mapper) {
            return ReferenceSyncResult::failed('No reference mapper registered for model: '.static::class);
        }

        return app(ReferenceSyncService::class)->resyncWithReference($this, $mapper);
    }

    /**
     * Unsync both main and reference records.
     */
    public function unsyncWithReference(): bool
    {
        $mapper = $this->getReferenceMapper();

        if (! $mapper) {
            return false;
        }

        return app(ReferenceSyncService::class)->unsyncWithReference($this, $mapper);
    }

    /**
     * Unsync only the reference record (keep main).
     */
    public function unsyncReference(): bool
    {
        $mapper = $this->getReferenceMapper();

        if (! $mapper) {
            return false;
        }

        return app(ReferenceSyncService::class)->unsyncReference($this, $mapper);
    }

    /**
     * Get the DID to sync as.
     *
     * Override this method to customize DID resolution.
     */
    protected function syncAsDid(): ?string
    {
        // Check for did column
        if (isset($this->did)) {
            return $this->did;
        }

        // Check for user relationship with did
        if (method_exists($this, 'user') && $this->user?->did) {
            return $this->user->did;
        }

        // Check for author relationship with did
        if (method_exists($this, 'author') && $this->author?->did) {
            return $this->author->did;
        }

        return null;
    }
}
