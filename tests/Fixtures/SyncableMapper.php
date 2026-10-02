<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpParity\Acceptance\Acceptance;

/**
 * Mapper for SyncableModel (extends TestMapper with different model class).
 */
class SyncableMapper extends TestMapper
{
    public function modelClass(): string
    {
        return SyncableModel::class;
    }

    /**
     * A test double standing in for an arbitrary collection, so it accepts freely.
     * A real mapper states a narrower policy.
     */
    public function accepts(): ?Acceptance
    {
        return Acceptance::anything();
    }
}
