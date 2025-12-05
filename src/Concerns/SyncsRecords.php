<?php

namespace SocialDept\AtpParity\Concerns;

use SocialDept\AtpParity\Sync\SyncResult;
use SocialDept\AtpParity\Sync\SyncService;

/**
 * Trait for Eloquent models that can be manually synced to AT Protocol.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait SyncsRecords
{
    use HasAtpRecord;

    /**
     * Sync this model to AT Protocol.
     *
     * If the model has a DID association (via did column or relationship),
     * it will be used. Otherwise, use syncAs() to specify the DID.
     */
    public function sync(): SyncResult
    {
        return app(SyncService::class)->sync($this);
    }

    /**
     * Sync this model as a specific user.
     */
    public function syncAs(string $did): SyncResult
    {
        return app(SyncService::class)->syncAs($did, $this);
    }

    /**
     * Resync the record on AT Protocol.
     */
    public function resync(): SyncResult
    {
        return app(SyncService::class)->resync($this);
    }

    /**
     * Unsync (delete) the record from AT Protocol.
     */
    public function unsync(): bool
    {
        return app(SyncService::class)->unsync($this);
    }

    /**
     * Check if this model has been synced to AT Protocol.
     */
    public function isSynced(): bool
    {
        return $this->hasAtpRecord();
    }
}
