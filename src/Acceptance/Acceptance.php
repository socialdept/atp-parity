<?php

namespace SocialDept\AtpParity\Acceptance;

use Closure;
use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpSchema\Data\Data;

/**
 * Which records a mapper will accept from the network.
 *
 * Records arrive from the whole network, so a mapper that accepts everything lets
 * any repo write rows in our database. Declaring nothing therefore accepts nothing.
 */
class Acceptance
{
    /**
     * @param  Closure(Data, array<string, mixed>, RecordMapper): bool  $permits
     */
    final private function __construct(
        protected Closure $permits,
    ) {
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
     * Accept only records from an actor who has connected their account, so we hold
     * credentials for the repo and an inbound record there may be our own write.
     *
     * Not named for authorship: a record another client wrote into a repo we hold
     * tokens for passes this. The test is the repo relationship.
     *
     * Needs `atp-parity.acceptance.is_connected_actor`.
     */
    public static function connectedActors(): static
    {
        return static::forLookup('is_connected_actor');
    }

    /**
     * Accept records from any actor we know, whether or not we can write for them.
     *
     * Strictly broader than {@see self::connectedActors()}, and a different question:
     * an actor identified by a signed JWT through an XRPC proxy is one we know and hold
     * no tokens for, so their content can be accepted and held until they sign in.
     *
     * Needs `atp-parity.acceptance.is_known_actor`. Deliberately not falling back to the
     * write lookup, which would turn "we can write here" into "we have heard of them".
     */
    public static function knownActors(): static
    {
        return static::forLookup('is_known_actor');
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

    /**
     * A policy backed by one of the configured actor lookups.
     *
     * An unconfigured lookup accepts nothing, which is the safe direction: absent must
     * not read as "yes".
     */
    protected static function forLookup(string $lookup): static
    {
        return static::when(function (Data $record, array $meta) use ($lookup): bool {
            $did = $meta['did'] ?? null;

            if (! is_string($did) || $did === '') {
                return false;
            }

            $resolver = config('atp-parity.acceptance.'.$lookup);

            if ($resolver === null) {
                return false;
            }

            return (bool) (is_string($resolver) ? app($resolver) : $resolver)($did);
        });
    }
}
