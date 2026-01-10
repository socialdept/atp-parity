<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a reference record is synced to AT Protocol.
 */
class ReferenceSynced
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly string $referenceUri,
        public readonly string $referenceCid,
        public readonly ?string $mainUri = null,
    ) {}
}
