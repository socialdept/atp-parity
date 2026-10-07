<?php

namespace SocialDept\AtpParity\Tests\Unit\Signals;

use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Signals\ParitySignal;
use SocialDept\AtpParity\Tests\Fixtures\ReferenceModel;
use SocialDept\AtpParity\Tests\Fixtures\TestMainMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestReferenceMapper;
use SocialDept\AtpParity\Tests\TestCase;
use SocialDept\AtpSchema\Data\Data;
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\SignalEvent;

/**
 * An echo of a reference record we already hold is skipped before it is applied.
 *
 * The unchanged-CID guard looked the model up by the main URI column and compared
 * the main CID column. A reference record's own URI and CID live in the reference
 * columns, so the lookup never found it and every echo of a reference write was
 * applied again as if it were news.
 */
class ParitySignalReferenceEchoTest extends TestCase
{
    private const REFERENCE_URI = 'at://did:plc:test/app.test.reference/ref1';

    private object $references;

    protected function setUp(): void
    {
        parent::setUp();

        $this->references = new class () extends TestReferenceMapper {
            public int $upserts = 0;

            public function upsert(Data $record, array $meta = []): ?\Illuminate\Database\Eloquent\Model
            {
                $this->upserts++;

                return parent::upsert($record, $meta);
            }
        };

        $registry = app(MapperRegistry::class);
        $registry->register(new TestMainMapper());
        $registry->register($this->references);

        ReferenceModel::create([
            'title' => 'Linked',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreimain',
            'atp_reference_uri' => self::REFERENCE_URI,
            'atp_reference_cid' => 'bafyreireference',
        ]);
    }

    private function event(string $cid): SignalEvent
    {
        return new SignalEvent(
            did: 'did:plc:test',
            timeUs: 1_700_000_000_000_000,
            kind: 'commit',
            commit: new CommitEvent(
                rev: '3kb3fge5lm32x',
                operation: 'update',
                collection: 'app.test.reference',
                rkey: 'ref1',
                record: (object) ['subject' => ['uri' => 'at://did:plc:test/app.test.main/abc', 'cid' => 'bafyreimain']],
                cid: $cid,
            ),
        );
    }

    public function test_an_echo_of_a_reference_we_hold_is_skipped(): void
    {
        app(ParitySignal::class)->handle($this->event('bafyreireference'));

        $this->assertSame(0, $this->references->upserts);
    }

    public function test_a_changed_reference_is_still_applied(): void
    {
        app(ParitySignal::class)->handle($this->event('bafyreichanged'));

        $this->assertSame(1, $this->references->upserts);
        $this->assertSame('bafyreichanged', ReferenceModel::first()->atp_reference_cid);
        $this->assertSame('at://did:plc:test/app.test.main/abc', ReferenceModel::first()->atp_uri);
    }
}
