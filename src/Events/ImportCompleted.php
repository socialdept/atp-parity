<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SocialDept\AtpParity\Import\ImportResult;

class ImportCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly ImportResult $result,
    ) {}
}
