<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a model is synced to AT Protocol.
 *
 * `rev` is the commit's revision and `record` the value as written, `$type`
 * included, so a listener can index the write before the firehose echoes it.
 * Both are null when the PDS did not report a commit.
 */
class RecordSynced
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly string $uri,
        public readonly string $cid,
        public readonly ?string $rev = null,
        public readonly ?array $record = null,
    ) {
    }
}
