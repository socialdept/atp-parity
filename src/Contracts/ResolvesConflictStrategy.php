<?php

namespace SocialDept\AtpParity\Contracts;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Sync\ConflictStrategy;

/**
 * Lets a mapper choose the conflict strategy per record.
 *
 * The configured strategy is global, but which side should win is often a
 * property of the individual record rather than the application. A model the app
 * authored and pushed outward has different authority from one that arrived by
 * import, and no single setting can express both.
 *
 * Opt-in: implement this only on mappers that need it. Everything else keeps
 * using `atp-parity.conflicts.strategy`.
 */
interface ResolvesConflictStrategy
{
    /**
     * The strategy to apply for this record, or null to use the configured one.
     *
     * @param  Model  $existing  The local model the incoming record conflicts with
     */
    public function conflictStrategy(Model $existing): ?ConflictStrategy;
}
