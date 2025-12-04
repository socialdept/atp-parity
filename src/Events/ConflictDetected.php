<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use SocialDept\AtpParity\Sync\PendingConflict;
use SocialDept\AtpSchema\Data\Data;

/**
 * Dispatched when a conflict is detected that requires manual resolution.
 */
class ConflictDetected
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly Data $record,
        public readonly array $meta,
        public readonly PendingConflict $conflict,
    ) {}
}
