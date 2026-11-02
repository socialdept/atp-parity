<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a model is unsynced from AT Protocol. `rev` is the delete
 * commit's revision, null when the PDS did not report one.
 */
class RecordUnsynced
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly string $uri,
        public readonly ?string $rev = null,
    ) {
    }
}
