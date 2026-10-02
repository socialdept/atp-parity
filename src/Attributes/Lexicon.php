<?php

namespace SocialDept\AtpParity\Attributes;

use Attribute;

/**
 * The NSID a mapper is for, resolved once and cached.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Lexicon
{
    public function __construct(public string $nsid)
    {
        //
    }
}
