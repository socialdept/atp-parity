<?php

namespace SocialDept\AtpParity\Tests\Unit\Signals;

use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Signals\ParitySignal;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\SignalEvent;

/**
 * A replayed historical event must not overwrite a record we already hold.
 *
 * The "CID unchanged" check cannot catch this. A replay carries the record's
 * *old* CID, which never matches the latest one stored, so without this guard
 * every past version is re-applied in turn — and under the default `remote`
 * conflict strategy, applied straight over newer local edits.
 */
class ParitySignalBackfillTest extends TestCase
{
    private ParitySignal $signal;

    private const URI = 'at://did:plc:test/app.test.record/abc123';

    protected function setUp(): void
    {
        parent::setUp();

        app(MapperRegistry::class)->register(new TestMapper());
        $this->signal = app(ParitySignal::class);
    }

    private function event(string $text, string $cid, ?bool $backfill): SignalEvent
    {
        return new SignalEvent(
            did: 'did:plc:test',
            timeUs: 1_700_000_000_000_000,
            kind: 'commit',
            commit: new CommitEvent(
                rev: '3kb3fge5lm32x',
                operation: 'create',
                collection: 'app.test.record',
                rkey: 'abc123',
                record: (object) ['text' => $text],
                cid: $cid,
            ),
            backfill: $backfill,
        );
    }

    public function test_a_backfilled_event_does_not_overwrite_a_record_we_already_hold(): void
    {
        // The shape that forced a production rollback: a record imported, then
        // edited locally, then the archive replayed from the beginning.
        TestModel::create([
            'content' => 'edited locally after import',
            'atp_uri' => self::URI,
            'atp_cid' => 'bafyreicurrent',
        ]);

        $this->signal->handle($this->event('the original remote version', 'bafyreiold', backfill: true));

        $this->assertSame('edited locally after import', TestModel::first()->content);
    }

    public function test_a_backfilled_event_still_creates_a_record_we_do_not_have(): void
    {
        // Backfill is how history reaches us in the first place — gating it must
        // not break the import it exists to serve.
        $this->signal->handle($this->event('imported from history', 'bafyreiold', backfill: true));

        $this->assertSame('imported from history', TestModel::first()?->content);
    }

    public function test_a_live_event_still_updates_an_existing_record(): void
    {
        TestModel::create([
            'content' => 'stale',
            'atp_uri' => self::URI,
            'atp_cid' => 'bafyreiprevious',
            'atp_synced_at' => now(),
        ]);

        $this->signal->handle($this->event('edited remotely just now', 'bafyreinew', backfill: false));

        $this->assertSame('edited remotely just now', TestModel::first()->content);
    }

    public function test_jetstream_events_without_a_backfill_flag_are_treated_as_live(): void
    {
        // Jetstream and firehose leave `backfill` null. Null must not read as
        // "historical", or those transports would stop applying updates.
        TestModel::create([
            'content' => 'stale',
            'atp_uri' => self::URI,
            'atp_cid' => 'bafyreiprevious',
            'atp_synced_at' => now(),
        ]);

        $this->signal->handle($this->event('edited remotely', 'bafyreinew', backfill: null));

        $this->assertSame('edited remotely', TestModel::first()->content);
    }

    public function test_an_app_can_opt_in_to_backfill_overwriting(): void
    {
        config()->set('atp-parity.backfill.overwrites_existing', true);

        TestModel::create([
            'content' => 'local',
            'atp_uri' => self::URI,
            'atp_cid' => 'bafyreicurrent',
            'atp_synced_at' => now(),
        ]);

        $this->signal->handle($this->event('replayed history wins', 'bafyreiold', backfill: true));

        $this->assertSame('replayed history wins', TestModel::first()->content);
    }

    public function test_a_construction_failure_is_observable_not_just_logged(): void
    {
        // Without a validation mode the exception is re-thrown rather than
        // swallowed, which is a different (and louder) path than the silent drop
        // this event exists to make visible.
        config()->set('atp-parity.validation.mode', \SocialDept\AtpParity\Enums\ValidationMode::Optimistic);

        \Illuminate\Support\Facades\Event::fake([\SocialDept\AtpParity\Events\RecordConstructionFailed::class]);

        // `text` is typed `string`, so an array for it is a TypeError the DTO
        // cannot recover from — the shape that silently discarded 994 records in
        // production while every health signal kept reporting normal.
        $event = new SignalEvent(
            did: 'did:plc:test',
            timeUs: 1,
            kind: 'commit',
            commit: new CommitEvent(
                rev: 'r',
                operation: 'create',
                collection: 'app.test.record',
                rkey: 'broken',
                record: (object) ['text' => ['not', 'a', 'string']],
                cid: 'bafyreibroken',
            ),
            backfill: false,
        );

        $this->signal->handle($event);

        \Illuminate\Support\Facades\Event::assertDispatched(
            \SocialDept\AtpParity\Events\RecordConstructionFailed::class,
            fn ($e) => $e->collection === 'app.test.record' && $e->rkey === 'broken',
        );
    }
}
