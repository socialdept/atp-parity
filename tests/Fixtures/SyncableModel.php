<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpParity\Concerns\SyncsWithAtp;

/**
 * Test model with SyncsWithAtp trait for unit testing.
 *
 * Extends TestModel so it gets the same mapper from the registry.
 */
class SyncableModel extends TestModel
{
    use SyncsWithAtp;

    protected $casts = [
        'atp_synced_at' => 'datetime',
    ];
}
