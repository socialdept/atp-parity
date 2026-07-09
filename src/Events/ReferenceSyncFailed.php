<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a reference record write fails.
 *
 * The main record may still have synced; this signals only the reference leg
 * so consumers can surface a stale reference or trigger a manual re-sync.
 */
class ReferenceSyncFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly string $error,
        public readonly ?string $referenceUri = null,
    ) {}
}
