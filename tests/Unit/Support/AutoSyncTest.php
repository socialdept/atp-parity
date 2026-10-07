<?php

namespace SocialDept\AtpParity\Tests\Unit\Support;

use RuntimeException;
use SocialDept\AtpParity\Support\AutoSync;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * The suppression scope has to end exactly where it was opened.
 *
 * Inbound upserts nest (a create replays parked references, each an upsert of
 * its own), and a handler can throw. A scope that ended early would let the rest
 * of the outer upsert write back, and one that leaked would silently stop every
 * later local edit from reaching the repo.
 */
class AutoSyncTest extends TestCase
{
    public function test_it_is_suppressed_only_inside_the_scope(): void
    {
        $this->assertFalse(AutoSync::isSuppressed());

        $inside = AutoSync::without(fn () => AutoSync::isSuppressed());

        $this->assertTrue($inside);
        $this->assertFalse(AutoSync::isSuppressed());
    }

    public function test_a_nested_scope_ending_leaves_the_outer_one_suppressed(): void
    {
        $afterInner = AutoSync::without(function () {
            AutoSync::without(fn () => null);

            return AutoSync::isSuppressed();
        });

        $this->assertTrue($afterInner);
        $this->assertFalse(AutoSync::isSuppressed());
    }

    public function test_an_exception_ends_the_scope(): void
    {
        try {
            AutoSync::without(fn () => throw new RuntimeException('handler failed'));
        } catch (RuntimeException) {
        }

        $this->assertFalse(AutoSync::isSuppressed());
    }

    public function test_it_returns_the_callback_result(): void
    {
        $this->assertSame('value', AutoSync::without(fn () => 'value'));
    }
}
