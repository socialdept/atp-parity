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
 * A create or update that arrives without a record is skipped, not applied.
 *
 * A delivery service may mark a stale event as superseded and send it with no
 * record body, because a newer version is already on its way. Hydrating an empty
 * record either throws, which stalls the consumer, or applies a blank one over
 * real content.
 */
class ParitySignalSupersededEventTest extends TestCase
{
    private const URI = 'at://did:plc:test/app.test.record/abc123';

    protected function setUp(): void
    {
        parent::setUp();

        app(MapperRegistry::class)->register(new TestMapper());
    }

    private function event(string $operation): SignalEvent
    {
        return new SignalEvent(
            did: 'did:plc:test',
            timeUs: 1_700_000_000_000_000,
            kind: 'commit',
            commit: new CommitEvent(
                rev: '3kb3fge5lm32x',
                operation: $operation,
                collection: 'app.test.record',
                rkey: 'abc123',
                record: null,
                cid: 'bafyreistale',
            ),
        );
    }

    public function test_an_update_without_a_record_leaves_the_model_alone(): void
    {
        TestModel::create([
            'content' => 'current',
            'atp_uri' => self::URI,
            'atp_cid' => 'bafyreicurrent',
            'atp_synced_at' => now(),
        ]);

        app(ParitySignal::class)->handle($this->event('update'));

        $this->assertSame('current', TestModel::first()->content);
        $this->assertSame('bafyreicurrent', TestModel::first()->atp_cid);
    }

    public function test_a_create_without_a_record_creates_nothing(): void
    {
        app(ParitySignal::class)->handle($this->event('create'));

        $this->assertSame(0, TestModel::count());
    }
}
