<?php

namespace SocialDept\AtpParity\Sync;

/**
 * Strategy for resolving conflicts between local and remote changes.
 */
enum ConflictStrategy: string
{
    /**
     * Remote (AT Protocol) is source of truth.
     * Local changes are overwritten.
     */
    case RemoteWins = 'remote';

    /**
     * Local database is source of truth.
     * Remote changes are ignored.
     */
    case LocalWins = 'local';

    /**
     * Compare timestamps and use the newest version.
     */
    case NewestWins = 'newest';

    /**
     * Flag conflict for manual review.
     * Neither version is applied automatically.
     */
    case Manual = 'manual';

    /**
     * Create from config value.
     */
    public static function fromConfig(): self
    {
        $strategy = config('parity.conflicts.strategy', 'remote');

        return self::tryFrom($strategy) ?? self::RemoteWins;
    }
}
