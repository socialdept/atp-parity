<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use SocialDept\AtpParity\PendingSync\PendingSync;

/**
 * Dispatched when a failed sync is captured for later retry.
 */
class PendingSyncCaptured
{
    use Dispatchable;

    public function __construct(
        public readonly PendingSync $pendingSync,
        public readonly Model $model,
    ) {}
}
