<?php

namespace SocialDept\AtpParity\Attributes;

use Attribute;

/**
 * The NSID a mapper is for.
 *
 * An attribute rather than a method because the collection a mapper handles is a
 * static fact about the class, resolved once and cached, which is what attributes
 * are good for. Laravel's `#[ObservedBy]` is the same shape.
 *
 * Guards deliberately do **not** work this way: they carry runtime logic, need to
 * compose through inheritance, and are evaluated per inbound record rather than
 * once at boot, where a `ReflectionClass` per call would be a real cost.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Lexicon
{
    public function __construct(public string $nsid)
    {
        //
    }
}
