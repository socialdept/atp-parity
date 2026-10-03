<?php

namespace SocialDept\AtpParity\Tests\Fixtures\Upcasters;

use SocialDept\AtpParity\Attributes\UpcastsFrom;
use SocialDept\AtpParity\Upcasting\Deprecation;
use SocialDept\AtpParity\Upcasting\Upcaster;

/**
 * A mapper only declares the current shape, so keeping a superseded property
 * populated for older readers has to happen on the write path.
 */
#[UpcastsFrom(lexicon: 'app.test.declared')]
class DualWritesPayload extends Upcaster
{
    public function applies(array $record): bool
    {
        return false;
    }

    public function apply(array $record): array
    {
        return $record;
    }

    public function onWrite(): ?Deprecation
    {
        return Deprecation::dualWrite('payload', from: 'text');
    }
}
