<?php

namespace SocialDept\AtpParity\Concerns;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpClient\Exceptions\AuthenticationException;
use SocialDept\AtpClient\Exceptions\OAuthSessionInvalidException;
use SocialDept\AtpParity\Enums\PendingSyncOperation;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\PendingSync\PendingSyncManager;
use SocialDept\AtpParity\Sync\SyncService;

/**
 * Trait for Eloquent models that automatically sync with AT Protocol.
 *
 * This trait sets up model observers to automatically create, update,
 * and delete records when the model is created, updated, or deleted.
 *
 * Override shouldAutoSync() and shouldAutoUnsync() to customize
 * the conditions under which auto-syncing occurs.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait AutoSyncsWithAtp
{
    use SyncsRecords;

    /**
     * Boot the AutoSyncsWithAtp trait.
     */
    public static function bootAutoSyncsWithAtp(): void
    {
        static::created(function ($model) {
            if ($model->shouldAutoSync()) {
                $did = $model->syncAsDid();

                if ($did) {
                    try {
                        app(SyncService::class)->syncAs($did, $model);
                    } catch (OAuthSessionInvalidException|AuthenticationException $e) {
                        static::capturePendingSync($model, $did, PendingSyncOperation::Sync);

                        throw $e;
                    }
                }
            }
        });

        static::updated(function ($model) {
            if ($model->isSynced() && $model->shouldAutoSync() && static::recordCouldHaveChanged($model)) {
                try {
                    app(SyncService::class)->resync($model);
                } catch (OAuthSessionInvalidException|AuthenticationException $e) {
                    $did = $model->getAtpDid() ?? $model->syncAsDid();

                    if ($did) {
                        static::capturePendingSync($model, $did, PendingSyncOperation::Resync);
                    }

                    throw $e;
                }
            }
        });

        static::deleted(function ($model) {
            if ($model->isSynced() && $model->shouldAutoUnsync()) {
                try {
                    app(SyncService::class)->unsync($model);
                } catch (OAuthSessionInvalidException|AuthenticationException $e) {
                    $did = $model->getAtpDid() ?? $model->syncAsDid();

                    if ($did) {
                        static::capturePendingSync($model, $did, PendingSyncOperation::Unsync);
                    }

                    throw $e;
                }
            }
        });
    }

    /**
     * Whether this update could have changed anything the record contains.
     *
     * An update to a column the record does not carry cannot change the record, so
     * resyncing on it writes an identical record into a repo we do not own. A model
     * row carries far more than its record does (counters, cached values, local
     * settings), and every one of them was a trigger before this.
     *
     * Answers true whenever the question cannot be settled, because the cost of a
     * wrong "no" is an edit that never reaches the PDS, while the cost of a wrong
     * "yes" is a redundant write the CID comparison in SyncService then catches.
     * The two layers fail in opposite directions on purpose.
     */
    protected static function recordCouldHaveChanged(Model $model): bool
    {
        $mapper = app(MapperRegistry::class)->forModel(get_class($model));

        if (! $mapper) {
            return true;
        }

        $columns = $mapper->recordColumns();

        // Null is a mapper that writes its own directions, so its columns are not
        // knowable. Empty is a declaration whose fields are all derived, where
        // gating on columns would block every write.
        if ($columns === null || $columns === []) {
            return true;
        }

        return $model->wasChanged($columns);
    }

    /**
     * Capture a pending sync for retry after reauth.
     */
    protected static function capturePendingSync(
        Model $model,
        string $did,
        PendingSyncOperation $operation
    ): void {
        $manager = app(PendingSyncManager::class);

        if ($manager->isEnabled()) {
            $manager->capture($did, $model, $operation);
        }
    }

    /**
     * Determine if the model should be auto-synced.
     *
     * Override this method to add custom conditions.
     */
    public function shouldAutoSync(): bool
    {
        return true;
    }

    /**
     * Determine if the model should be auto-unsynced when deleted.
     *
     * Override this method to add custom conditions.
     */
    public function shouldAutoUnsync(): bool
    {
        return true;
    }

    /**
     * Get the DID to use for syncing.
     *
     * Override this method to customize DID resolution.
     */
    public function syncAsDid(): ?string
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
