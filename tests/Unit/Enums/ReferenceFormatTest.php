<?php

namespace SocialDept\AtpParity\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use SocialDept\AtpParity\Enums\ReferenceFormat;

class ReferenceFormatTest extends TestCase
{
    public function test_at_uri_case_has_correct_value(): void
    {
        $this->assertSame('at-uri', ReferenceFormat::AtUri->value);
    }

    public function test_strong_ref_case_has_correct_value(): void
    {
        $this->assertSame('strongref', ReferenceFormat::StrongRef->value);
    }

    public function test_can_create_from_string_value(): void
    {
        $atUri = ReferenceFormat::from('at-uri');
        $strongRef = ReferenceFormat::from('strongref');

        $this->assertSame(ReferenceFormat::AtUri, $atUri);
        $this->assertSame(ReferenceFormat::StrongRef, $strongRef);
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $result = ReferenceFormat::tryFrom('invalid');

        $this->assertNull($result);
    }
}
