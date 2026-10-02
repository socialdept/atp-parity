<?php

namespace SocialDept\AtpParity\Tests\Unit\Upcasting;

use LogicException;
use SocialDept\AtpParity\Tests\Fixtures\Upcasters\AddBorderWidth;
use SocialDept\AtpParity\Tests\Fixtures\Upcasters\OrphanedUpcaster;
use SocialDept\AtpParity\Tests\Fixtures\Upcasters\SplitPalette;
use SocialDept\AtpParity\Tests\Fixtures\Upcasters\UndeclaredUpcaster;
use SocialDept\AtpParity\Tests\TestCase;
use SocialDept\AtpParity\Upcasting\UpcasterChain;

/**
 * Records of any past shape are brought up to the current one before anything reads
 * them, so mappers never carry a fallback per superseded field.
 *
 * Two invariants carry the safety of the whole mechanism, and both have their own
 * test below. A step that still applies to a current record re-dirties the row on
 * every read, and the resulting resync writes to a PDS. A step that is not
 * idempotent cannot be run safely where a shape difference is ambiguous, which is
 * every generation that added only an optional field.
 */
class UpcasterChainTest extends TestCase
{
    private function chain(): UpcasterChain
    {
        return new UpcasterChain([SplitPalette::class, AddBorderWidth::class]);
    }

    private function oldRecord(): array
    {
        return ['colors' => ['primary' => 'red']];
    }

    public function test_it_brings_an_old_record_up_to_the_current_shape(): void
    {
        $record = $this->chain()->upcast('app.test.theme', $this->oldRecord());

        $this->assertSame(['primary' => 'red'], $record['light']);
        $this->assertSame('1px', $record['borderWidth']);
    }

    /**
     * The step that supersedes `colors` leaves it in place, because a lexicon cannot
     * drop a property readers still expect, and certainly not a required one.
     */
    public function test_it_leaves_the_superseded_property_in_place(): void
    {
        $record = $this->chain()->upcast('app.test.theme', $this->oldRecord());

        $this->assertSame(['primary' => 'red'], $record['colors']);
    }

    public function test_steps_run_in_declared_order_regardless_of_registration_order(): void
    {
        $chain = new UpcasterChain([AddBorderWidth::class, SplitPalette::class]);

        $record = $chain->upcast('app.test.theme', $this->oldRecord());

        $this->assertArrayHasKey('light', $record);
        $this->assertArrayHasKey('borderWidth', $record, 'A step ordered after another must still see its output.');
    }

    /**
     * The invariant that keeps a read from becoming a write. A step that still
     * applies to a current record re-dirties the row every time it is read.
     */
    public function test_a_current_record_is_left_untouched(): void
    {
        $current = $this->chain()->upcast('app.test.theme', $this->oldRecord());

        $this->assertSame($current, $this->chain()->upcast('app.test.theme', $current));
        $this->assertTrue($this->chain()->isCurrent('app.test.theme', $current));
    }

    public function test_running_the_chain_twice_equals_running_it_once(): void
    {
        $once = $this->chain()->upcast('app.test.theme', $this->oldRecord());
        $twice = $this->chain()->upcast('app.test.theme', $once);

        $this->assertSame($once, $twice);
    }

    /**
     * A record from a writer we do not host arrives in whatever shape that client
     * writes, which a version field we invented would never have described.
     */
    public function test_it_upcasts_a_record_written_by_another_client(): void
    {
        $theirs = ['colors' => ['primary' => 'blue'], 'somethingWeDoNotKnow' => true];

        $record = $this->chain()->upcast('app.test.theme', $theirs);

        $this->assertSame(['primary' => 'blue'], $record['light']);
        $this->assertTrue($record['somethingWeDoNotKnow'], 'An unknown property must survive untouched.');
    }

    public function test_a_lexicon_with_no_upcasters_passes_through(): void
    {
        $record = ['anything' => 1];

        $this->assertSame($record, $this->chain()->upcast('app.test.unknown', $record));
    }

    /**
     * Reporting without writing, which is what lets a sweep state its blast radius
     * before touching a repo.
     */
    public function test_it_reports_which_steps_a_record_is_behind(): void
    {
        $this->assertSame(
            [SplitPalette::class, AddBorderWidth::class],
            $this->chain()->pending('app.test.theme', $this->oldRecord()),
        );

        $this->assertFalse($this->chain()->isCurrent('app.test.theme', $this->oldRecord()));
    }

    public function test_the_write_side_dual_writes_the_superseded_property(): void
    {
        $record = $this->chain()->applyDeprecations('app.test.theme', ['light' => ['primary' => 'green']]);

        $this->assertSame(['primary' => 'green'], $record['colors']);
    }

    public function test_an_upcaster_without_the_attribute_fails_loudly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no #[UpcastsFrom]');

        new UpcasterChain([UndeclaredUpcaster::class]);
    }

    public function test_an_upcaster_naming_an_unregistered_predecessor_fails_loudly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('not a registered upcaster');

        new UpcasterChain([OrphanedUpcaster::class]);
    }
}
