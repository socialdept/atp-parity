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
     * Needs `atp-parity.acceptance.local_dids`. Without it nothing is accepted, so
     * a missing lookup cannot read as "everything is local".
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
     * The same lookup as {@see self::ownWritesOnly()} today, kept separate because a
     * repo we can write to is not the same as one we have heard of.
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
