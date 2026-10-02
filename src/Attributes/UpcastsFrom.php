<?php

namespace SocialDept\AtpParity\Attributes;

use Attribute;

/**
 * Declares which lexicon an upcaster transforms, and where it sits in the chain.
 *
 * An attribute because both facts are static properties of the class, resolved once
 * and cached. Keeping them here rather than in a config array means a change to
 * either is a change in one place.
 *
 * Ordering is relative to another upcaster rather than an integer, so inserting a
 * step never renumbers the ones around it.
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
