<?php

namespace SocialDept\AtpParity\Concerns;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpClient\Exceptions\AuthenticationException;
use SocialDept\AtpClient\Exceptions\OAuthSessionInvalidException;
use SocialDept\AtpParity\Enums\PendingSyncOperation;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\PendingSync\PendingSyncManager;
use SocialDept\AtpParity\Support\AutoSync;
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
 * Nothing syncs inside {@see AutoSync::without()}, which is where every inbound
 * record is applied.
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
            if (AutoSync::isSuppressed()) {
                return;
            }

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
            if (AutoSync::isSuppressed()) {
                return;
            }

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
            if (AutoSync::isSuppressed()) {
                return;
            }

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
     * INFO: true whenever the question cannot be settled. A wrong "no" is an edit
     * that never reaches the PDS, a wrong "yes" is a redundant write the CID
     * comparison in SyncService catches.
     */
    protected static function recordCouldHaveChanged(Model $model): bool
    {
        $mapper = app(MapperRegistry::class)->forModel(get_class($model));

        if (! $mapper) {
            return true;
        }

        $columns = $mapper->recordColumns();

        // Null: directions written by hand, columns unknowable. Empty: all fields
        // derived, so gating on columns would block every write.
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
