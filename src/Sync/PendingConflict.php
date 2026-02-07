<?php

namespace SocialDept\AtpParity\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Model for storing pending conflicts requiring manual resolution.
 */
class PendingConflict extends Model
{
    protected $guarded = [];

    protected $casts = [
        'local_data' => 'array',
        'remote_data' => 'array',
        'resolved_at' => 'datetime',
    ];

    /**
     * Get the table name from config.
     */
    public function getTable(): string
    {
        return config('atp-parity.conflicts.table', 'parity_conflicts');
    }

    /**
     * Get the related model.
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Check if this conflict is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if this conflict has been resolved.
     */
    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    /**
     * Check if this conflict was dismissed.
     */
    public function isDismissed(): bool
    {
        return $this->status === 'dismissed';
    }

    /**
     * Resolve the conflict with the local version.
     */
    public function resolveWithLocal(): void
    {
        $this->update([
            'status' => 'resolved',
            'resolution' => 'local',
            'resolved_at' => now(),
        ]);
    }

    /**
     * Resolve the conflict with the remote version.
     */
    public function resolveWithRemote(): void
    {
        $model = $this->model;

        if ($model) {
            $model->fill($this->remote_data);
            $model->save();
        }

        $this->update([
            'status' => 'resolved',
            'resolution' => 'remote',
            'resolved_at' => now(),
        ]);
    }

    /**
     * Dismiss this conflict without resolving.
     */
    public function dismiss(): void
    {
        $this->update([
            'status' => 'dismissed',
            'resolved_at' => now(),
        ]);
    }

    /**
     * Scope to pending conflicts.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to resolved conflicts.
     */
    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    /**
     * Scope to conflicts for a specific model.
     */
    public function scopeForModel($query, Model $model)
    {
        return $query->where('model_type', get_class($model))
            ->where('model_id', $model->getKey());
    }
}
