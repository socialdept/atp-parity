<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpParity\Acceptance\Acceptance;
use SocialDept\AtpParity\RecordMapper;

/**
 * Declares one field, so `content` feeds the record and `local_only` does not.
 */
class AutoSyncMapper extends RecordMapper
{
    public function recordClass(): string
    {
        return TestRecord::class;
    }

    public function modelClass(): string
    {
        return AutoSyncModel::class;
    }

    public function fields(): array
    {
        return ['text' => 'content'];
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
