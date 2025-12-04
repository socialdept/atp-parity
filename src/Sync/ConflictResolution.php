<?php

namespace SocialDept\AtpParity\Sync;

use Illuminate\Database\Eloquent\Model;

/**
 * Value object representing the result of conflict resolution.
 */
readonly class ConflictResolution
{
    public function __construct(
        public bool $resolved,
        public string $winner,
        public ?Model $model = null,
        public ?PendingConflict $pending = null,
    ) {}

    /**
     * Check if the conflict was resolved.
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * Check if the conflict requires manual resolution.
     */
    public function isPending(): bool
    {
        return ! $this->resolved && $this->pending !== null;
    }

    /**
     * Create resolution where remote wins.
     */
    public static function remoteWins(Model $model): self
    {
        return new self(
            resolved: true,
            winner: 'remote',
            model: $model,
        );
    }

    /**
     * Create resolution where local wins.
     */
    public static function localWins(Model $model): self
    {
        return new self(
            resolved: true,
            winner: 'local',
            model: $model,
        );
    }

    /**
     * Create pending resolution for manual review.
     */
    public static function pending(PendingConflict $conflict): self
    {
        return new self(
            resolved: false,
            winner: 'manual',
            pending: $conflict,
        );
    }
}
