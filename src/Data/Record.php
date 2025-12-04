<?php

namespace SocialDept\AtpParity\Data;

use SocialDept\AtpClient\Contracts\Recordable;
use SocialDept\AtpSchema\Data\Data;

/**
 * Base class for custom AT Protocol records.
 *
 * Extends atp-schema's Data for full compatibility with the ecosystem,
 * including union type support, validation, equality, and hashing.
 *
 * Implements Recordable for seamless atp-client integration.
 */
abstract class Record extends Data implements Recordable
{
    /**
     * Get the record type (alias for getLexicon for Recordable interface).
     */
    public function getType(): string
    {
        return static::getLexicon();
    }
}
