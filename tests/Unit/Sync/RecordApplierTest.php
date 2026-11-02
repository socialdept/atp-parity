<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialDept\AtpParity\Acceptance\Acceptance;
use SocialDept\AtpParity\Data\IncomingRecord;
use SocialDept\AtpParity\Enums\ApplyOutcome;
use SocialDept\AtpParity\Enums\ValidationMode;
use SocialDept\AtpParity\Events\RecordConstructionFailed;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Sync\RecordApplier;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * `preview()` exists so an app can run a new transport beside the old one and
 * compare decisions before letting it write. That comparison is worthless if the
 * preview decides differently from `apply()`, and dangerous if it writes.
 */
class RecordApplierTest extends TestCase
{
    private const URI = 'at://did:plc:test/app.test.record/abc123';

    private RecordApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();

        app(MapperRegistry::class)->register(new TestMapper());
        $this->applier = app(RecordApplier::class);
    }

    private function incoming(string $cid = 'bafyreinew', bool $backfill = false, string $collection = 'app.test.record'): IncomingRecord
    {
        return new IncomingRecord('did:plc:test', $collection, 'abc123', $cid, ['text' => 'remote'], $backfill);
    }

    private function held(string $cid = 'bafyreicurrent'): TestModel
    {
        return TestModel::create([
            'content' => 'local',
            'atp_uri' => self::URI,
            'atp_cid' => $cid,
            'atp_synced_at' => now()->addMinute(),
        ]);
    }

    /**
     * Each case runs the preview first and asserts nothing changed, then applies
     * the same record and expects the same answer.
     *
     * @return array<string, array{\Closure(self): IncomingRecord, ApplyOutcome}>
     */
    public static function cases(): array
    {
        return [
            'a new record' => [fn (self $t) => $t->incoming(), ApplyOutcome::Created],
            'a remote edit' => [function (self $t) {
                $t->held();

                return $t->incoming();
            }, ApplyOutcome::Updated],
            'our own echo' => [function (self $t) {
                $t->held('bafyreinew');

                return $t->incoming('bafyreinew');
            }, ApplyOutcome::Unchanged],
            'a backfill replay' => [function (self $t) {
                $t->held();

                return $t->incoming('bafyreiold', backfill: true);
            }, ApplyOutcome::BackfillHeld],
            'an unmapped collection' => [fn (self $t) => $t->incoming(collection: 'app.test.other'), ApplyOutcome::Unmapped],
            'a delete' => [function (self $t) {
                $t->held();

                return IncomingRecord::deletion('did:plc:test', 'app.test.record', 'abc123');
            }, ApplyOutcome::Deleted],
            'a delete for nothing held' => [fn () => IncomingRecord::deletion('did:plc:test', 'app.test.record', 'abc123'), ApplyOutcome::Missing],
        ];
    }

    #[DataProvider('cases')]
    public function test_preview_writes_nothing_and_agrees_with_apply(\Closure $arrange, ApplyOutcome $expected): void
    {
        $incoming = $arrange($this);
        $before = TestModel::all()->toArray();

        $this->assertSame($expected, $this->applier->preview($incoming));
        $this->assertEquals($before, TestModel::all()->toArray());

        $this->assertSame($expected, $this->applier->apply($incoming));
    }

    public function test_a_refusal_is_reported_without_writing(): void
    {
        app(MapperRegistry::class)->register(new class () extends TestMapper {
            public function accepts(): ?Acceptance
            {
                return Acceptance::none();
            }
        });

        $this->assertSame(ApplyOutcome::Refused, $this->applier->preview($this->incoming()));
        $this->assertSame(ApplyOutcome::Refused, $this->applier->apply($this->incoming()));
        $this->assertSame(0, TestModel::count());
    }

    public function test_preview_reports_a_conflict_without_resolving_it(): void
    {
        TestModel::create([
            'content' => 'edited locally',
            'atp_uri' => self::URI,
            'atp_cid' => 'bafyreicurrent',
            'atp_synced_at' => now()->subHour(),
        ]);

        $this->assertSame(ApplyOutcome::Conflict, $this->applier->preview($this->incoming()));
        $this->assertSame('edited locally', TestModel::first()->content);

        $this->assertSame(ApplyOutcome::ConflictResolved, $this->applier->apply($this->incoming()));
    }

    public function test_preview_reports_a_broken_record_without_dispatching(): void
    {
        config()->set('atp-parity.validation.mode', ValidationMode::Optimistic);
        Event::fake([RecordConstructionFailed::class]);

        $broken = new IncomingRecord('did:plc:test', 'app.test.record', 'abc123', 'bafyrei', ['text' => ['not', 'a', 'string']]);

        $this->assertSame(ApplyOutcome::Unconstructable, $this->applier->preview($broken));
        Event::assertNotDispatched(RecordConstructionFailed::class);

        $this->assertSame(ApplyOutcome::Unconstructable, $this->applier->apply($broken));
        Event::assertDispatched(RecordConstructionFailed::class);
    }
}
