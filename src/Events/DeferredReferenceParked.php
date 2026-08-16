<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Foundation\Events\Dispatchable;

class DeferredReferenceParked
{
    use Dispatchable;

    public function __construct(public string $referenceUri, public string $targetUri, public string $collection)
    {
    }
}
