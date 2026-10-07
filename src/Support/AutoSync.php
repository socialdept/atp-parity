<?php

namespace SocialDept\AtpParity\Support;

/**
 * A scope inside which no auto-sync trait writes to a repo.
 *
 * Applying an inbound record saves the model, and a save is exactly what the
 * auto-sync traits listen for. Without this, a record arriving from the network
 * is written straight back to the repo it came from: an echo of our own write
 * becomes a second write, and a genuine remote edit is overwritten with whatever
 * the model held before the mapper's `afterUpsert()` landed the rest of it.
 *
 * The package applies every inbound record inside this scope. An app wraps its
 * own work in it when that work also mirrors the repo rather than changing it.
 */
final class AutoSync
{
    private static int $depth = 0;

    /**
     * Run a callback with auto-sync suppressed, restoring it afterwards.
     *
     * A counter rather than a flag, so a nested scope ending does not re-enable
     * sync for the scope still around it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function without(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isSuppressed(): bool
    {
        return self::$depth > 0;
    }
}
