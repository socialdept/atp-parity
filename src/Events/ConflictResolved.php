<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use SocialDept\AtpParity\Sync\ConflictStrategy;

/**
 * A local/remote conflict was resolved, under any strategy.
 *
 * `ConflictDetected` only fires for `manual`, which means the strategies that
 * silently discard one side — `remote` above all — left no trace at all. An
 * application cannot notice a wrong policy it is never told about.
 */
class ConflictResolved
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly ConflictStrategy $strategy,
        public readonly ?string $winner,
        public readonly string $uri,
    ) {
    }
}
