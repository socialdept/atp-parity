<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ImportStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $did,
        public readonly string $collection,
    ) {}
}
