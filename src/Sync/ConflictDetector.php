<?php

namespace SocialDept\AtpParity\Sync;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\SyncsWithAtp;
use SocialDept\AtpSchema\Data\Data;

/**
 * Detects conflicts between local and remote record versions.
 */
class ConflictDetector
{
    /**
     * Check if there's a conflict between local model and remote record.
     */
    public function hasConflict(Model $model, Data $record, string $cid): bool
    {
        // No conflict if model doesn't have local changes
        if (! $this->modelHasLocalChanges($model)) {
            return false;
        }

        // No conflict if CID matches (same version)
        if ($this->getCid($model) === $cid) {
            return false;
        }

        return true;
    }

    /**
     * Check if the model has local changes since last sync.
     */
    protected function modelHasLocalChanges(Model $model): bool
    {
        // Use trait method if available
        if ($this->usesTrait($model, SyncsWithAtp::class)) {
            return $model->hasLocalChanges();
        }

        // Fallback: compare updated_at with a sync timestamp if available
        $syncedAt = $model->getAttribute('atp_synced_at');

        if (! $syncedAt) {
            return true;
        }

        $updatedAt = $model->getAttribute('updated_at');

        if (! $updatedAt) {
            return false;
        }

        return $updatedAt > $syncedAt;
    }

    /**
     * Get the CID from a model.
     */
    protected function getCid(Model $model): ?string
    {
        $column = config('parity.columns.cid', 'atp_cid');

        return $model->getAttribute($column);
    }

    /**
     * Check if a model uses a specific trait.
     *
     * @param  class-string  $trait
     */
    protected function usesTrait(Model $model, string $trait): bool
    {
        return in_array($trait, class_uses_recursive($model));
    }
}
