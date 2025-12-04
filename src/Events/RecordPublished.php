<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a model is published to AT Protocol.
 */
class RecordPublished
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly string $uri,
        public readonly string $cid,
    ) {}
}
