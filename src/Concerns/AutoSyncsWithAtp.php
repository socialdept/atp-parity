<?php

namespace SocialDept\AtpParity\Concerns;

use SocialDept\AtpParity\Publish\PublishService;

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
    use PublishesRecords;

    /**
     * Boot the AutoSyncsWithAtp trait.
     */
    public static function bootAutoSyncsWithAtp(): void
    {
        static::created(function ($model) {
            if ($model->shouldAutoSync()) {
                $did = $model->syncAsDid();
                if ($did) {
                    app(PublishService::class)->publishAs($did, $model);
                }
            }
        });

        static::updated(function ($model) {
            if ($model->isPublished() && $model->shouldAutoSync()) {
                app(PublishService::class)->update($model);
            }
        });

        static::deleted(function ($model) {
            if ($model->isPublished() && $model->shouldAutoUnsync()) {
                app(PublishService::class)->delete($model);
            }
        });
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
