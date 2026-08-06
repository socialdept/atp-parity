<?php

namespace SocialDept\AtpParity\Contracts;

use DateTimeInterface;
use SocialDept\AtpParity\Data\DeferredReference;

/**
 * Holds reference records waiting on a main record that has not arrived.
 *
 * Keyed by target URI, because that is the question actually asked: when a main
 * record is created, what was waiting for it?
 */
interface DeferredReferenceStore
{
    /**
     * Park a reference. Idempotent on the reference URI — re-delivery of an
     * orphan must not accumulate duplicates.
     */
    public function park(DeferredReference $reference): void;

    /**
     * Every reference waiting on this target, oldest first.
     *
     * @return array<int, DeferredReference>
     */
    public function awaiting(string $targetUri): array;

    /** Drop one reference, once applied or abandoned. */
    public function release(string $referenceUri): void;

    /**
     * Drop everything waiting on a target. Used when the target is refused
     * outright, so its orphans do not linger until the TTL.
     *
     * @return int How many were dropped
     */
    public function releaseFor(string $targetUri): int;

    /**
     * Remove references parked before the cutoff.
     *
     * @return array<int, DeferredReference> Those removed, so callers can report them
     */
    public function prune(DateTimeInterface $before): array;

    /** How many references are parked. Surfaced for monitoring. */
    public function count(): int;
}
