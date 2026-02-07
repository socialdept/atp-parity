<?php

namespace SocialDept\AtpParity\Concerns;

use SocialDept\AtpSchema\Data\Data;

/**
 * Trait for models that sync bidirectionally with AT Protocol.
 *
 * Extends HasAtpRecord with additional sync tracking and conflict handling.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait SyncsWithAtp
{
    use HasAtpRecord;

    /**
     * Get the column name for tracking the last sync timestamp.
     */
    public function getAtpSyncedAtColumn(): string
    {
        return 'atp_synced_at';
    }

    /**
     * Get the timestamp of the last sync.
     */
    public function getAtpSyncedAt(): ?\DateTimeInterface
    {
        $column = $this->getAtpSyncedAtColumn();

        return $this->getAttribute($column);
    }

    /**
     * Mark the model as synced with the given metadata.
     */
    public function markAsSynced(string $uri, string $cid): void
    {
        $uriColumn = config('atp-parity.columns.uri', 'atp_uri');
        $cidColumn = config('atp-parity.columns.cid', 'atp_cid');
        $syncColumn = $this->getAtpSyncedAtColumn();

        $this->setAttribute($uriColumn, $uri);
        $this->setAttribute($cidColumn, $cid);
        $this->setAttribute($syncColumn, now());
    }

    /**
     * Check if the model has local changes since last sync.
     */
    public function hasLocalChanges(): bool
    {
        $syncedAt = $this->getAtpSyncedAt();

        if (! $syncedAt) {
            return true;
        }

        $updatedAt = $this->getAttribute('updated_at');

        if (! $updatedAt) {
            return false;
        }

        return $updatedAt > $syncedAt;
    }

    /**
     * Update the model from a remote record.
     */
    public function updateFromRecord(Data $record, string $uri, string $cid): void
    {
        $mapper = $this->getAtpMapper();

        if (! $mapper) {
            return;
        }

        $mapper->updateModel($this, $record, [
            'uri' => $uri,
            'cid' => $cid,
        ]);

        $this->setAttribute($this->getAtpSyncedAtColumn(), now());
    }

    /**
     * Boot the trait.
     */
    public static function bootSyncsWithAtp(): void
    {
        // Hook into model events if needed
    }
}
