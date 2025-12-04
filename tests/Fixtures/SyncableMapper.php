<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

/**
 * Mapper for SyncableModel (extends TestMapper with different model class).
 */
class SyncableMapper extends TestMapper
{
    public function modelClass(): string
    {
        return SyncableModel::class;
    }
}
