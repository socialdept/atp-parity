<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ImportProgress
{
    use Dispatchable;

    public function __construct(
        public readonly string $did,
        public readonly string $collection,
        public readonly int $recordsSynced,
        public readonly ?string $cursor = null,
    ) {}
}
