<?php

namespace SocialDept\AtpParity\Import;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks import progress for a DID/collection pair.
 *
 * @property int $id
 * @property string $did
 * @property string $collection
 * @property string $status
 * @property string|null $cursor
 * @property int $records_synced
 * @property int $records_skipped
 * @property int $records_failed
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property string|null $error
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ImportState extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'did',
        'collection',
        'status',
        'cursor',
        'records_synced',
        'records_skipped',
        'records_failed',
        'started_at',
        'completed_at',
        'error',
    ];

    protected $casts = [
        'records_synced' => 'integer',
        'records_skipped' => 'integer',
        'records_failed' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('parity.import.state_table', 'parity_import_states');
    }

    /**
     * Start the import process for this state.
     */
    public function markStarted(): self
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'error' => null,
        ]);

        return $this;
    }

    /**
     * Mark the import as completed.
     */
    public function markCompleted(): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'cursor' => null,
        ]);

        return $this;
    }

    /**
     * Mark the import as failed.
     */
    public function markFailed(string $error): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => $error,
        ]);

        return $this;
    }

    /**
     * Update progress during import.
     */
    public function updateProgress(int $synced, int $skipped = 0, int $failed = 0, ?string $cursor = null): self
    {
        $this->increment('records_synced', $synced);

        if ($skipped > 0) {
            $this->increment('records_skipped', $skipped);
        }

        if ($failed > 0) {
            $this->increment('records_failed', $failed);
        }

        if ($cursor !== null) {
            $this->update(['cursor' => $cursor]);
        }

        return $this;
    }

    /**
     * Check if this import can be resumed.
     */
    public function canResume(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS
            || $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if this import is complete.
     */
    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if this import is currently running.
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /**
     * Scope to pending imports.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to in-progress imports.
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    /**
     * Scope to completed imports.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to failed imports.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to incomplete imports (pending, in_progress, or failed).
     */
    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_FAILED,
        ]);
    }

    /**
     * Scope to resumable imports (in_progress or failed with cursor).
     */
    public function scopeResumable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_IN_PROGRESS,
            self::STATUS_FAILED,
        ]);
    }

    /**
     * Find or create an import state for a DID/collection pair.
     */
    public static function findOrCreateFor(string $did, string $collection): self
    {
        return static::firstOrCreate(
            ['did' => $did, 'collection' => $collection],
            ['status' => self::STATUS_PENDING]
        );
    }

    /**
     * Convert to ImportResult.
     */
    public function toResult(): ImportResult
    {
        return new ImportResult(
            did: $this->did,
            collection: $this->collection,
            recordsSynced: $this->records_synced,
            recordsSkipped: $this->records_skipped,
            recordsFailed: $this->records_failed,
            completed: $this->isComplete(),
            cursor: $this->cursor,
            error: $this->error,
        );
    }
}
