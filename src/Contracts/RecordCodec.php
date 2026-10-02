<?php

namespace SocialDept\AtpParity\Contracts;

/**
 * A two-way translation too large to sit inside a field declaration, such as a theme
 * palette or a rich-text body.
 *
 * Keeps the two directions adjacent and testable without a model or a database.
 */
interface RecordCodec
{
    public function get(mixed $value): mixed;


    public function set(mixed $value): mixed;
}
