<?php

namespace SocialDept\AtpParity\Tests\Fixtures\Upcasters;

use SocialDept\AtpParity\Attributes\UpcastsFrom;
use SocialDept\AtpParity\Upcasting\Deprecation;
use SocialDept\AtpParity\Upcasting\Upcaster;

/** The other half of the transition: nothing reads the old property any more. */
#[UpcastsFrom(lexicon: 'app.test.declared')]
class DropsLegacyField extends Upcaster
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
        return Deprecation::drop('derived');
    }
}
