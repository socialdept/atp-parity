<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a model is unsynced from AT Protocol.
 */
class RecordUnsynced
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly string $uri,
    ) {}
}
