<?php

namespace SocialDept\AtpParity\Tests\Unit\Upcasting;

use SocialDept\AtpParity\Tests\Fixtures\DeclarativeMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\Fixtures\Upcasters\DropsLegacyField;
use SocialDept\AtpParity\Tests\Fixtures\Upcasters\DualWritesPayload;
use SocialDept\AtpParity\Tests\TestCase;
use SocialDept\AtpParity\Upcasting\UpcasterChain;

/**
 * Deprecations have to reach the record a mapper actually builds.
 *
 * A mapper only ever declares the current shape, which is the point of upcasting, so
 * keeping a superseded property populated cannot be its job. Asserting on
 * applyDeprecations() alone would pass while nothing called it.
 */
class DeprecationOnWriteTest extends TestCase
{
    private function withUpcaster(string $class): void
    {
        $this->app->instance(UpcasterChain::class, new UpcasterChain([$class]));
    }

    public function test_a_dual_written_property_appears_in_the_record_a_mapper_builds(): void
    {
        $this->withUpcaster(DualWritesPayload::class);

        $record = (new DeclarativeMapper())->toRecord(new TestModel(['content' => 'hello']))->toArray();

        $this->assertSame('hello', $record['text']);
        $this->assertSame('hello', $record['payload'], 'The superseded property must be filled from the current one.');
    }

    public function test_a_dropped_property_is_absent_from_the_record_a_mapper_builds(): void
    {
        $this->withUpcaster(DropsLegacyField::class);

        $record = (new DeclarativeMapper())->toRecord(new TestModel(['content' => 'hello']))->toArray();

        $this->assertArrayNotHasKey('derived', $record);
    }

    public function test_a_lexicon_with_no_deprecations_is_untouched(): void
    {
        $this->app->instance(UpcasterChain::class, new UpcasterChain([]));

        $record = (new DeclarativeMapper())->toRecord(new TestModel(['content' => 'hello']))->toArray();

        $this->assertSame('derived:hello', $record['derived']);
    }
}
