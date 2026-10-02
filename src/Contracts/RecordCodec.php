<?php

namespace SocialDept\AtpParity\Contracts;

/**
 * A two-way translation too large to sit inside a field declaration.
 *
 * Exists so the awkward cases stay expressible without the declaration growing
 * to meet them. A theme palette or a rich-text body is hundreds of lines in each
 * direction, and forcing that into a field map is how a small declarative layer
 * turns into a language.
 *
 * The value of moving it here rather than leaving it as two private methods on a
 * mapper is that the two directions become adjacent and the pair becomes
 * testable on its own, with no model and no database. Nothing checks that a
 * mapper's two private halves are inverses of each other.
 */
interface RecordCodec
{
    /**
     * Record value to model attribute value.
     */
    public function get(mixed $value): mixed;

    /**
     * Model attribute value to record value.
     */
    public function set(mixed $value): mixed;
}
