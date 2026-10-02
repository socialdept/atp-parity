<?php

namespace SocialDept\AtpParity\Upcasting;

/**
 * One step forward from an older record shape to a newer one, inferred from the
 * record itself because lexicons carry no version.
 *
 * Two invariants, both of which break silently:
 *
 * - `applies()` must be false for a record already current, or every read dirties
 *   the row and the resulting resync writes to a PDS.
 * - `apply()` must be safe to run twice, which is the only thing that makes a step
 *   safe where a shape difference is ambiguous.
 */
abstract class Upcaster
{
    /**
     * @param  array<string, mixed>  $record
     */
    abstract public function applies(array $record): bool;

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    abstract public function apply(array $record): array;

    /**
     * Declared beside the read side so both halves of a deprecation ship together.
     */
    public function onWrite(): ?Deprecation
    {
        return null;
    }
}
