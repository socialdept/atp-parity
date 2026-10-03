<?php

namespace SocialDept\AtpParity\Attributes;

use Attribute;

/**
 * Which lexicon an upcaster transforms, and where it sits in the chain.
 *
 * Ordering is relative rather than numeric, so inserting a step never renumbers the
 * ones around it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class UpcastsFrom
{
    /**
     * @param  class-string|null  $after  The upcaster this one must run after.
     */
    public function __construct(
        public string $lexicon,
        public ?string $after = null,
    ) {
        //
    }
}
