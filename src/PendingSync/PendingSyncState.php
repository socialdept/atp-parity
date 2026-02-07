<?php

namespace SocialDept\AtpParity\PendingSync;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Enums\PendingSyncOperation;

/**
 * Database model for storing pending sync operations.
 *
 * @property int $id
 * @property string $pending_id
 * @property string $did
 * @property string $model_class
 * @property string $model_id
 * @property string $operation
 * @property string|null $reference_mapper_class
 * @property int $attempts
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class PendingSyncState extends Model
{
    protected $fillable = [
        'pending_id',
        'did',
        'model_class',
        'model_id',
        'operation',
        'reference_mapper_class',
        'attempts',
    ];

    protected $casts = [
        'attempts' => 'integer',
    ];

    public function getTable(): string
    {
        return config('atp-parity.pending_syncs.table', 'parity_pending_syncs');
    }

    /**
     * Convert to PendingSync value object.
     */
    public function toPendingSync(): PendingSync
    {
        return new PendingSync(
            id: $this->pending_id,
            did: $this->did,
            modelClass: $this->model_class,
            modelId: $this->model_id,
            operation: PendingSyncOperation::from($this->operation),
            referenceMapperClass: $this->reference_mapper_class,
            createdAt: $this->created_at->toImmutable(),
            attempts: $this->attempts,
        );
    }

    /**
     * Scope to a specific DID.
     */
    public function scopeForDid(Builder $query, string $did): Builder
    {
        return $query->where('did', $did);
    }

    /**
     * Scope to a specific model.
     */
    public function scopeForModel(Builder $query, string $modelClass, int|string $modelId): Builder
    {
        return $query->where('model_class', $modelClass)
            ->where('model_id', $modelId);
    }

    /**
     * Scope to expired entries.
     */
    public function scopeExpired(Builder $query): Builder
    {
        $ttl = config('atp-parity.pending_syncs.ttl', 3600);

        return $query->where('created_at', '<', now()->subSeconds($ttl));
    }
}
