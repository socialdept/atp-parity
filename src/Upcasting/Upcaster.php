<?php

namespace SocialDept\AtpParity\Upcasting;

/**
 * One step forward from an older record shape to a newer one.
 *
 * Lexicons are not versioned documents. They evolve by adding, so there is no
 * version on a record to read, and a version we invented would be absent from
 * exactly the records that most need migrating: those written before we added it,
 * and those written by other clients who will never set it.
 *
 * So a step infers the shape it applies to from the record itself. That also makes
 * it work on records we did not write, which is the decisive advantage.
 *
 * Two rules, both load-bearing:
 *
 * - `applies()` must be false for a record already in the current shape, or every
 *   read re-dirties the row and the resulting resync loop writes to a PDS.
 * - `apply()` must be safe to run twice. Where a shape difference is ambiguous,
 *   which happens whenever a generation added only an optional field, idempotence
 *   is the only thing that makes the step safe at all.
 */
abstract class Upcaster
{
    /**
     * Whether this record is in the older shape this step transforms.
     *
     * @param  array<string, mixed>  $record
     */
    abstract public function applies(array $record): bool;

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    abstract public function apply(array $record): array;

    /**
     * What the write side should do about the property this step supersedes.
     *
     * Declared next to the read side on purpose: deprecating a property has two
     * halves, and separating them is how one ships without the other.
     */
    public function onWrite(): ?Deprecation
    {
        return null;
    }
}
