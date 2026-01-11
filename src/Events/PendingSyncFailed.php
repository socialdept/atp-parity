<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SocialDept\AtpParity\PendingSync\PendingSync;
use Throwable;

/**
 * Dispatched when a pending sync retry fails with an exception.
 */
class PendingSyncFailed
{
    use Dispatchable;

    public function __construct(
        public readonly PendingSync $pendingSync,
        public readonly Throwable $exception,
    ) {}
}
