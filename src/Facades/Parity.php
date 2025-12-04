<?php

namespace SocialDept\AtpParity\Facades;

use Illuminate\Support\Facades\Facade;
use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpParity\MapperRegistry;

/**
 * @method static void register(RecordMapper $mapper)
 * @method static RecordMapper|null forRecord(string $recordClass)
 * @method static RecordMapper|null forModel(string $modelClass)
 * @method static RecordMapper|null forLexicon(string $nsid)
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
