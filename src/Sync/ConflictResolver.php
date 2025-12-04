<?php

namespace SocialDept\AtpParity\Sync;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpParity\Events\ConflictDetected;
use SocialDept\AtpSchema\Data\Data;

/**
 * Resolves conflicts between local and remote record versions.
 */
class ConflictResolver
{
    /**
     * Resolve a conflict according to the specified strategy.
     */
    public function resolve(
        Model $model,
        Data $record,
        array $meta,
        RecordMapper $mapper,
        ConflictStrategy $strategy
    ): ConflictResolution {
        return match ($strategy) {
            ConflictStrategy::RemoteWins => $this->applyRemote($model, $record, $meta, $mapper),
            ConflictStrategy::LocalWins => $this->keepLocal($model),
            ConflictStrategy::NewestWins => $this->compareAndApply($model, $record, $meta, $mapper),
            ConflictStrategy::Manual => $this->flagForReview($model, $record, $meta, $mapper),
        };
    }

    /**
     * Apply the remote version, overwriting local changes.
     */
    protected function applyRemote(
        Model $model,
        Data $record,
        array $meta,
        RecordMapper $mapper
    ): ConflictResolution {
        $mapper->updateModel($model, $record, $meta);
        $model->save();

        return ConflictResolution::remoteWins($model);
    }

    /**
     * Keep the local version, ignoring remote changes.
     */
    protected function keepLocal(Model $model): ConflictResolution
    {
        return ConflictResolution::localWins($model);
    }

    /**
     * Compare timestamps and apply the newest version.
     */
    protected function compareAndApply(
        Model $model,
        Data $record,
        array $meta,
        RecordMapper $mapper
    ): ConflictResolution {
        $localUpdatedAt = $model->getAttribute('updated_at');

        // Try to get remote timestamp from record
        $remoteCreatedAt = $record->createdAt ?? null;

        // If we can't compare, default to remote wins
        if (! $localUpdatedAt || ! $remoteCreatedAt) {
            return $this->applyRemote($model, $record, $meta, $mapper);
        }

        // Compare timestamps
        if ($localUpdatedAt > $remoteCreatedAt) {
            return $this->keepLocal($model);
        }

        return $this->applyRemote($model, $record, $meta, $mapper);
    }

    /**
     * Flag the conflict for manual review.
     */
    protected function flagForReview(
        Model $model,
        Data $record,
        array $meta,
        RecordMapper $mapper
    ): ConflictResolution {
        // Create a pending conflict record
        $conflict = PendingConflict::create([
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'uri' => $meta['uri'] ?? null,
            'local_data' => $model->toArray(),
            'remote_data' => $this->buildRemoteData($record, $meta, $mapper),
            'status' => 'pending',
        ]);

        // Dispatch event for notification
        event(new ConflictDetected($model, $record, $meta, $conflict));

        return ConflictResolution::pending($conflict);
    }

    /**
     * Build the remote data array for storage.
     */
    protected function buildRemoteData(Data $record, array $meta, RecordMapper $mapper): array
    {
        // Create a temporary model with the remote data
        $tempModel = $mapper->toModel($record, $meta);

        return $tempModel->toArray();
    }
}
