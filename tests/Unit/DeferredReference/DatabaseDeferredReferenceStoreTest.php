<?php

namespace SocialDept\AtpParity\Tests\Unit\DeferredReference;

use DateTimeImmutable;
use SocialDept\AtpParity\Data\DeferredReference;
use SocialDept\AtpParity\DeferredReference\DatabaseDeferredReferenceStore;
use SocialDept\AtpParity\Tests\TestCase;

class DatabaseDeferredReferenceStoreTest extends TestCase
{
    private DatabaseDeferredReferenceStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new DatabaseDeferredReferenceStore();
    }

    private function reference(
        string $referenceUri = 'at://did:plc:a/app.test.reference/1',
        string $targetUri = 'at://did:plc:a/app.test.main/1',
        ?DateTimeImmutable $parkedAt = null,
    ): DeferredReference {
        return new DeferredReference(
            referenceUri: $referenceUri,
            targetUri: $targetUri,
            collection: 'app.test.reference',
            did: 'did:plc:a',
            cid: 'bafyreiabc',
            record: ['document' => $targetUri],
            parkedAt: $parkedAt ?? new DateTimeImmutable(),
        );
    }

    public function test_parks_and_retrieves_by_target(): void
    {
        $this->store->park($this->reference());

        $awaiting = $this->store->awaiting('at://did:plc:a/app.test.main/1');

        $this->assertCount(1, $awaiting);
        $this->assertSame('at://did:plc:a/app.test.reference/1', $awaiting[0]->referenceUri);
        $this->assertSame('app.test.reference', $awaiting[0]->collection);
        $this->assertSame('bafyreiabc', $awaiting[0]->cid);
    }

    public function test_round_trips_the_raw_record_body(): void
    {
        // Stored verbatim so replay re-validates through the mapper exactly as a
        // fresh delivery would, even if the lexicon changed while it was parked.
        $this->store->park($this->reference());

        $this->assertSame(
            ['document' => 'at://did:plc:a/app.test.main/1'],
            $this->store->awaiting('at://did:plc:a/app.test.main/1')[0]->record,
        );
    }

    public function test_awaiting_returns_nothing_for_an_unknown_target(): void
    {
        $this->store->park($this->reference());

        $this->assertSame([], $this->store->awaiting('at://did:plc:a/app.test.main/other'));
    }

    public function test_parking_the_same_reference_twice_does_not_duplicate(): void
    {
        // At-least-once delivery and cursor rewinds both re-deliver orphans.
        $this->store->park($this->reference());
        $this->store->park($this->reference());

        $this->assertCount(1, $this->store->awaiting('at://did:plc:a/app.test.main/1'));
        $this->assertSame(1, $this->store->count());
    }

    public function test_awaiting_returns_oldest_first(): void
    {
        $this->store->park($this->reference(
            referenceUri: 'at://did:plc:a/app.test.reference/2',
            parkedAt: new DateTimeImmutable('2026-01-02 00:00:00'),
        ));
        $this->store->park($this->reference(
            referenceUri: 'at://did:plc:a/app.test.reference/1',
            parkedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
        ));

        $order = array_map(
            fn ($r) => $r->referenceUri,
            $this->store->awaiting('at://did:plc:a/app.test.main/1'),
        );

        $this->assertSame([
            'at://did:plc:a/app.test.reference/1',
            'at://did:plc:a/app.test.reference/2',
        ], $order);
    }

    public function test_release_removes_a_single_reference(): void
    {
        $this->store->park($this->reference('at://did:plc:a/app.test.reference/1'));
        $this->store->park($this->reference('at://did:plc:a/app.test.reference/2'));

        $this->store->release('at://did:plc:a/app.test.reference/1');

        $this->assertCount(1, $this->store->awaiting('at://did:plc:a/app.test.main/1'));
        $this->assertSame(1, $this->store->count());
    }

    public function test_release_for_drops_everything_waiting_on_a_target(): void
    {
        // Used when the target is refused outright, so its orphans do not linger
        // until the TTL.
        $this->store->park($this->reference('at://did:plc:a/app.test.reference/1'));
        $this->store->park($this->reference('at://did:plc:a/app.test.reference/2'));
        $this->store->park($this->reference(
            referenceUri: 'at://did:plc:a/app.test.reference/3',
            targetUri: 'at://did:plc:a/app.test.main/other',
        ));

        $dropped = $this->store->releaseFor('at://did:plc:a/app.test.main/1');

        $this->assertSame(2, $dropped);
        $this->assertSame([], $this->store->awaiting('at://did:plc:a/app.test.main/1'));
        $this->assertCount(1, $this->store->awaiting('at://did:plc:a/app.test.main/other'));
    }

    public function test_prune_removes_only_entries_older_than_the_cutoff(): void
    {
        $this->store->park($this->reference(
            referenceUri: 'at://did:plc:a/app.test.reference/old',
            parkedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
        ));
        $this->store->park($this->reference(
            referenceUri: 'at://did:plc:a/app.test.reference/new',
            parkedAt: new DateTimeImmutable('2026-06-01 00:00:00'),
        ));

        $expired = $this->store->prune(new DateTimeImmutable('2026-03-01 00:00:00'));

        $this->assertCount(1, $expired);
        $this->assertSame('at://did:plc:a/app.test.reference/old', $expired[0]->referenceUri);
        $this->assertSame(1, $this->store->count());
    }

    public function test_prune_returns_what_it_removed_so_expiry_is_observable(): void
    {
        $this->store->park($this->reference(parkedAt: new DateTimeImmutable('2026-01-01 00:00:00')));

        $expired = $this->store->prune(new DateTimeImmutable('2026-03-01 00:00:00'));

        // The command emits an event per entry — a number silently going down is
        // exactly what we are trying to avoid.
        $this->assertSame('at://did:plc:a/app.test.main/1', $expired[0]->targetUri);
        $this->assertSame('app.test.reference', $expired[0]->collection);
    }

    public function test_count_reports_everything_parked(): void
    {
        $this->assertSame(0, $this->store->count());

        $this->store->park($this->reference('at://did:plc:a/app.test.reference/1'));
        $this->store->park($this->reference(
            referenceUri: 'at://did:plc:a/app.test.reference/2',
            targetUri: 'at://did:plc:a/app.test.main/other',
        ));

        $this->assertSame(2, $this->store->count());
    }

    public function test_meta_matches_the_shape_a_live_delivery_produces(): void
    {
        $meta = $this->reference()->meta();

        $this->assertSame('at://did:plc:a/app.test.reference/1', $meta['uri']);
        $this->assertSame('bafyreiabc', $meta['cid']);
        $this->assertSame('did:plc:a', $meta['did']);
        $this->assertSame('1', $meta['rkey']);
    }
}
