<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SocialDept\AtpParity\PendingSync\PendingSync;

/**
 * Dispatched after a pending sync retry attempt.
 */
class PendingSyncRetried
{
    use Dispatchable;

    public function __construct(
        public readonly PendingSync $pendingSync,
        public readonly bool $success,
    ) {}
}
