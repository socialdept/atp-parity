<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An inbound record could not be built into its DTO and was dropped.
 *
 * Silent by nature: the record never reaches a mapper, the archive cursor still
 * advances, and the only trace is a log line. A malformed lexicon field can
 * discard every record of a collection this way while every other health signal
 * keeps reporting normal.
 */
class RecordConstructionFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $did,
        public readonly string $collection,
        public readonly string $rkey,
        public readonly string $error,
    ) {}
}
