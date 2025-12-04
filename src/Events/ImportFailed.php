<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ImportFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $did,
        public readonly string $collection,
        public readonly string $error,
    ) {}
}
