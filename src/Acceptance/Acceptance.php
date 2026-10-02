<?php

namespace SocialDept\AtpParity\Acceptance;

use Closure;
use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpSchema\Data\Data;

/**
 * Which records a mapper will accept from the network.
 *
 * Every mapper is an ingest boundary. Records arrive from the whole network, so a
 * mapper that accepts everything lets any repo write rows in our database. That
 * used to be guarded by remembering to apply a trait, which is the wrong shape for
 * something whose failure mode is silent and total.
 *
 * So a mapper declares what it accepts and the base class refuses when nothing is
 * declared. Accepting anything is still available and still correct for some
 * collections, but it has to be written down.
 *
 * Policies compose, because a real condition is usually several: a repo we write
 * to, carrying a subject we host, that we have not already seen.
 */
class Acceptance
{
    /**
     * @param  Closure(Data, array<string, mixed>, RecordMapper): bool  $permits
     */
    final private function __construct(protected Closure $permits)
    {
        //
    }

    /**
     * @param  callable(Data, array<string, mixed>, RecordMapper): bool  $permits
     */
    public static function when(callable $permits): static
    {
        return new static(Closure::fromCallable($permits));
    }

    /**
     * Accept every record in the collection, from any repo.
     *
     * Legitimate for a genuinely public collection, and deliberately verbose so it
     * reads as a decision in review rather than as the absence of one.
     */
    public static function anything(): static
    {
        return static::when(fn () => true);
    }

    public static function none(): static
    {
        return static::when(fn () => false);
    }

    /**
     * Accept only records in a repo we write to.
     *
     * Whether a DID is one of ours is something only the host app knows, so it
     * supplies the lookup through `atp-parity.acceptance.local_dids`. Without one
     * configured this accepts nothing, which is the safe direction: a missing
     * lookup must not read as "everything is local".
     */
    public static function ownWritesOnly(): static
    {
        return static::when(function (Data $record, array $meta): bool {
            $did = $meta['did'] ?? null;

            return is_string($did) && $did !== '' && static::didIsLocal($did);
        });
    }

    /**
     * Accept records whose repo belongs to a user we know about.
     *
     * The same lookup as {@see self::ownWritesOnly()} today. They are separate names
     * because they answer different questions and will diverge: a repo we can write
     * to is not the same as a repo we have heard of.
     */
    public static function localDids(): static
    {
        return static::ownWritesOnly();
    }

    public function and(self $other): static
    {
        $mine = $this->permits;
        $theirs = $other->permits;

        return static::when(
            fn (Data $record, array $meta, RecordMapper $mapper): bool => $mine($record, $meta, $mapper)
                && $theirs($record, $meta, $mapper)
        );
    }

    public function or(self $other): static
    {
        $mine = $this->permits;
        $theirs = $other->permits;

        return static::when(
            fn (Data $record, array $meta, RecordMapper $mapper): bool => $mine($record, $meta, $mapper)
                || $theirs($record, $meta, $mapper)
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function permits(Data $record, array $meta, RecordMapper $mapper): bool
    {
        return ($this->permits)($record, $meta, $mapper);
    }

    protected static function didIsLocal(string $did): bool
    {
        $resolver = config('atp-parity.acceptance.local_dids');

        if ($resolver === null) {
            return false;
        }

        if (is_string($resolver)) {
            $resolver = app($resolver);
        }

        return (bool) $resolver($did);
    }
}
