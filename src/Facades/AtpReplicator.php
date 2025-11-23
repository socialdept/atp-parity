<?php

namespace SocialDept\AtpReplicator\Facades;

use Illuminate\Support\Facades\Facade;

class AtpReplicator extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'atp-replicator';
    }
}
