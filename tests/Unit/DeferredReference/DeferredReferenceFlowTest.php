<?php

namespace SocialDept\AtpParity\Tests\Unit\DeferredReference;

use Illuminate\Support\Facades\Event;
use SocialDept\AtpParity\Contracts\DeferredReferenceStore;
use SocialDept\AtpParity\Events\DeferredReferenceParked;
use SocialDept\AtpParity\Events\DeferredReferenceResolved;
use SocialDept\AtpParity\Tests\Fixtures\ReferenceModel;
use SocialDept\AtpParity\Tests\Fixtures\TestAtUriReferenceMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestReferenceRecord;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * The behaviour the deferred store exists for: a reference record arriving
 * before the main record it points at.
 *
 * A reference carries no content of its own — only a strong-ref — so it can only
 * be applied once its target is local. Delivery order is not guaranteed, and
 * dropping an orphan is silent data loss because the delivering consumer has
 * already acked the event and moved its cursor on.
 */
class DeferredReferenceFlowTest extends TestCase
{
    private TestAtUriReferenceMapper $mapper;

    private DeferredReferenceStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new TestAtUriReferenceMapper();
        $this->store = app(DeferredReferenceStore::class);
    }

    private function referenceRecord(string $mainUri = 'at://did:plc:a/app.test.main/1'): TestReferenceRecord
    {
        return TestReferenceRecord::fromArray(['document' => $mainUri]);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $referenceUri = 'at://did:plc:a/app.test.reference/1'): array
    {
        return [
            'uri' => $referenceUri,
            'cid' => 'bafyreiref',
            'did' => 'did:plc:a',
            'rkey' => '1',
        ];
    }

    public function test_reference_arriving_first_is_parked_not_dropped(): void
    {
        Event::fake([DeferredReferenceParked::class]);

        $result = $this->mapper->upsert($this->referenceRecord(), $this->meta());

        // Nothing applied yet, but nothing lost either.
        $this->assertNull($result);
        $this->assertSame(0, ReferenceModel::count());
        $this->assertCount(1, $this->store->awaiting('at://did:plc:a/app.test.main/1'));

        Event::assertDispatched(DeferredReferenceParked::class);
    }

    public function test_a_reference_never_creates_a_row(): void
    {
        // Before this was implemented, an inbound reference fell through to
        // create: a blank row whose atp_uri had been overwritten with the
        // reference's own URI, corrupting the column that stores its target.
        $this->mapper->upsert($this->referenceRecord(), $this->meta());

        $this->assertSame(0, ReferenceModel::count());
    }

    public function test_reference_applies_immediately_when_the_target_exists(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Existing',
            'atp_uri' => 'at://did:plc:a/app.test.main/1',
        ]);

        $result = $this->mapper->upsert($this->referenceRecord(), $this->meta());

        $this->assertNotNull($result);
        $this->assertTrue($result->is($model));

        // Stamped onto the existing row — the reference columns, not the main ones.
        $model->refresh();
        $this->assertSame('at://did:plc:a/app.test.reference/1', $model->atp_reference_uri);
        $this->assertSame('bafyreiref', $model->atp_reference_cid);
        $this->assertSame('at://did:plc:a/app.test.main/1', $model->atp_uri);

        // Nothing parked: there was nothing to wait for.
        $this->assertSame(0, $this->store->count());
    }

    public function test_re_delivering_a_parked_orphan_does_not_duplicate_it(): void
    {
        $this->mapper->upsert($this->referenceRecord(), $this->meta());
        $this->mapper->upsert($this->referenceRecord(), $this->meta());

        $this->assertSame(1, $this->store->count());
    }

    public function test_re_delivering_an_applied_reference_is_idempotent(): void
    {
        ReferenceModel::create(['title' => 'Existing', 'atp_uri' => 'at://did:plc:a/app.test.main/1']);

        $first = $this->mapper->upsert($this->referenceRecord(), $this->meta());
        $second = $this->mapper->upsert($this->referenceRecord(), $this->meta());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, ReferenceModel::count());
    }

    public function test_a_reference_without_a_target_is_ignored_entirely(): void
    {
        $record = TestReferenceRecord::fromArray([]);

        $this->assertNull($this->mapper->upsert($record, $this->meta()));
        $this->assertSame(0, $this->store->count());
        $this->assertSame(0, ReferenceModel::count());
    }

    public function test_two_references_can_wait_on_the_same_target(): void
    {
        $this->mapper->upsert($this->referenceRecord(), $this->meta('at://did:plc:a/app.test.reference/1'));
        $this->mapper->upsert($this->referenceRecord(), $this->meta('at://did:plc:a/app.test.reference/2'));

        $this->assertCount(2, $this->store->awaiting('at://did:plc:a/app.test.main/1'));
    }

    public function test_parking_survives_as_a_durable_row(): void
    {
        // Durable rather than in-memory on purpose: the delivering consumer has
        // already acked, so a worker restart must not lose the orphan.
        $this->mapper->upsert($this->referenceRecord(), $this->meta());

        $this->assertDatabaseHas('parity_deferred_references', [
            'reference_uri' => 'at://did:plc:a/app.test.reference/1',
            'target_uri' => 'at://did:plc:a/app.test.main/1',
        ]);
    }

    public function test_a_disabled_store_neither_parks_nor_throws(): void
    {
        config()->set('atp-parity.deferred_references.enabled', false);

        $this->assertNull($this->mapper->upsert($this->referenceRecord(), $this->meta()));
        $this->assertSame(0, $this->store->count());
    }

    public function test_resolution_events_are_not_fired_while_parked(): void
    {
        Event::fake([DeferredReferenceResolved::class]);

        $this->mapper->upsert($this->referenceRecord(), $this->meta());

        Event::assertNotDispatched(DeferredReferenceResolved::class);
    }
}
