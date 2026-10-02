<?php

namespace SocialDept\AtpParity\Tests\Fixtures\Upcasters;

use SocialDept\AtpParity\Attributes\UpcastsFrom;
use SocialDept\AtpParity\Upcasting\Upcaster;

#[UpcastsFrom(lexicon: 'app.test.theme', after: SplitPalette::class)]
class AddBorderWidth extends Upcaster
{
    public function applies(array $record): bool
    {
        return isset($record['light']) && ! isset($record['borderWidth']);
    }

    public function apply(array $record): array
    {
        $record['borderWidth'] = '1px';

        return $record;
    }
}
