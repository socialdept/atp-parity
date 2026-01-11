<?php

namespace SocialDept\AtpParity\Concerns;

use SocialDept\AtpParity\Sync\ReferenceSyncService;

/**
 * Trait for Eloquent models that automatically sync both main and reference records.
 *
 * This trait sets up model observers to automatically create, update,
 * and delete both main and reference records when the model changes.
 *
 * Use this instead of AutoSyncsWithAtp when your model needs both
 * a main record (third-party lexicon) and a reference record (your lexicon).
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait AutoSyncsWithReference
{
    use HasReferenceRecord;

    /**
     * Boot the AutoSyncsWithReference trait.
     */
    public static function bootAutoSyncsWithReference(): void
    {
        static::created(function ($model) {
            if ($model->shouldAutoSyncReference()) {
                $did = $model->syncAsDid();
                $mapper = $model->getReferenceMapper();

                if ($did && $mapper) {
                    app(ReferenceSyncService::class)->syncWithReference($did, $model, $mapper);
                }
            }
        });

        static::updated(function ($model) {
            if ($model->isFullySynced() && $model->shouldAutoSyncReference()) {
                $mapper = $model->getReferenceMapper();

                if ($mapper) {
                    // Resync BOTH main and reference records
                    app(ReferenceSyncService::class)->resyncWithReference($model, $mapper);
                }
            }
        });

        static::deleted(function ($model) {
            if ($model->shouldAutoUnsyncReference()) {
                $mapper = $model->getReferenceMapper();

                if ($mapper) {
                    app(ReferenceSyncService::class)->unsyncWithReference($model, $mapper);
                }
            }
        });
    }

    /**
     * Determine if the model should auto-sync main + reference.
     *
     * Override this method to add custom conditions.
     */
    public function shouldAutoSyncReference(): bool
    {
        return true;
    }

    /**
     * Determine if the model should auto-unsync when deleted.
     *
     * Override this method to add custom conditions.
     */
    public function shouldAutoUnsyncReference(): bool
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