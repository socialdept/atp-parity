<?php

namespace SocialDept\AtpParity\Facades;

use Illuminate\Support\Facades\Facade;
use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\PendingSync\PendingSyncRetryResult;

/**
 * @method static void register(RecordMapper $mapper)
 * @method static RecordMapper|null forRecord(string $recordClass)
 * @method static RecordMapper|null forModel(string $modelClass)
 * @method static array<RecordMapper> forModelAll(string $modelClass)
 * @method static RecordMapper|null forLexicon(string $nsid)
 * @method static PendingSyncRetryResult retryPendingSyncs(string $did)
 * @method static bool hasPendingSyncs(string $did)
 * @method static int countPendingSyncs(string $did)
 *
 * @see MapperRegistry
 */
class Parity extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'parity';
    }
}
