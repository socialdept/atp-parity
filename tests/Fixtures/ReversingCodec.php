<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpParity\Contracts\RecordCodec;

/**
 * A deliberately asymmetric codec: it lowercases on the way in, so the round trip
 * is not an identity and a lossy field has something real to tolerate.
 */
class ReversingCodec implements RecordCodec
{
    public function get(mixed $value): mixed
    {
        return strtolower(strrev((string) $value));
    }

    public function set(mixed $value): mixed
    {
        return strrev((string) $value);
    }
}
