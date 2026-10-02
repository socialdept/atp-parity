<?php

namespace SocialDept\AtpParity\Tests\Fixtures\Upcasters;

use SocialDept\AtpParity\Attributes\UpcastsFrom;
use SocialDept\AtpParity\Upcasting\Upcaster;

/** Declares a predecessor that is not registered alongside it. */
#[UpcastsFrom(lexicon: 'app.test.orphan', after: SplitPalette::class)]
class OrphanedUpcaster extends Upcaster
{
    public function applies(array $record): bool
    {
        return true;
    }

    public function apply(array $record): array
    {
        return $record;
    }
}
