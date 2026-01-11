<?php

namespace SocialDept\AtpParity\Concerns;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpClient\Exceptions\AuthenticationException;
use SocialDept\AtpClient\Exceptions\OAuthSessionInvalidException;
use SocialDept\AtpParity\Enums\PendingSyncOperation;
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
            if ($model->isSynced() && $model->shouldAutoSync()) {
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
