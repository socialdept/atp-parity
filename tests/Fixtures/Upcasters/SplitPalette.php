<?php

namespace SocialDept\AtpParity\Tests\Fixtures\Upcasters;

use SocialDept\AtpParity\Attributes\UpcastsFrom;
use SocialDept\AtpParity\Upcasting\Deprecation;
use SocialDept\AtpParity\Upcasting\Upcaster;

/**
 * Supersedes a single `colors` map with a `light` palette.
 *
 * The fingerprint is the absence of `light`, not the presence of `colors`: `colors`
 * survives into the new shape for readers that predate the change, so its presence
 * proves nothing on its own.
 */
#[UpcastsFrom(lexicon: 'app.test.theme')]
class SplitPalette extends Upcaster
{
    public function applies(array $record): bool
    {
        return isset($record['colors']) && ! isset($record['light']);
    }

    public function apply(array $record): array
    {
        $record['light'] = $record['colors'];

        return $record;
    }

    public function onWrite(): ?Deprecation
    {
        return Deprecation::dualWrite('colors', from: 'light');
    }
}
