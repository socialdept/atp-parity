<?php

namespace SocialDept\AtpParity\Tests\Fixtures\Upcasters;

use SocialDept\AtpParity\Upcasting\Upcaster;

/** No attribute, so it declares no lexicon and would never run. */
class UndeclaredUpcaster extends Upcaster
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
