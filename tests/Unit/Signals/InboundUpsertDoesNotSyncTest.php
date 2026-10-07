<?php

namespace SocialDept\AtpParity\Tests\Unit\Signals;

use Mockery;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Signals\ParitySignal;
use SocialDept\AtpParity\Sync\ReferenceSyncResult;
use SocialDept\AtpParity\Sync\ReferenceSyncService;
use SocialDept\AtpParity\Sync\SyncResult;
use SocialDept\AtpParity\Sync\SyncService;
use SocialDept\AtpParity\Tests\Fixtures\AutoSyncModel;
use SocialDept\AtpParity\Tests\Fixtures\AutoSyncReferenceModel;
use SocialDept\AtpParity\Tests\Fixtures\PublishingMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestMainMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestRecord;
use SocialDept\AtpParity\Tests\Fixtures\TestReferenceMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestReferenceRecord;
use SocialDept\AtpParity\Tests\TestCase;
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\SignalEvent;

/**
 * Applying an inbound record must never write to a repo.
 *
 * The save that applies a record is a model update like any other, so a model
 * that auto-syncs answered it by resyncing, inside the signal handler and before
 * `afterUpsert()` had landed the rest of the content. An echo of our own write
 * became a second write, and a genuine remote edit was overwritten with the
 * stale content the model still held. A mapper whose `afterUpsert()` then saved
 * again (publishing, say) produced a third.
 *
 * The mapper here does exactly that, so every save the inbound path can make is
 * one an auto-sync would act on.
 */
class InboundUpsertDoesNotSyncTest extends TestCase
{
    private const URI = 'at://did:plc:test/app.test.record/abc123';

    /** @var array<int, string> */
    private array $outbound = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbound = [];

        $sync = Mockery::mock(SyncService::class);
        $sync->shouldReceive('syncAs', 'syncAsWithMapper', 'resync', 'resyncWithMapper')
            ->andReturnUsing(function () {
                $this->outbound[] = 'sync';

                return SyncResult::success(self::URI, 'bafyreiwritten');
            });
        $this->app->instance(SyncService::class, $sync);

        $references = Mockery::mock(ReferenceSyncService::class);
        $references->shouldReceive('syncWithReference', 'resyncWithReference')
            ->andReturnUsing(function () {
                $this->outbound[] = 'reference';

                return ReferenceSyncResult::failed('should not be reached');
            });
        $this->app->instance(ReferenceSyncService::class, $references);

        app(MapperRegistry::class)->register(new PublishingMapper());
    }

    private function event(string $operation, string $text, string $cid): SignalEvent
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
                record: (object) ['text' => $text],
                cid: $cid,
            ),
        );
    }

    private function syncedModel(): AutoSyncModel
    {
        $model = AutoSyncModel::create([
            'content' => 'before',
            'did' => 'did:plc:test',
            'atp_uri' => self::URI,
            'atp_cid' => 'bafyreiprevious',
            'atp_synced_at' => now(),
        ]);

        $this->outbound = [];

        return $model;
    }

    public function test_a_remote_edit_is_applied_without_writing_back(): void
    {
        $model = $this->syncedModel();

        app(ParitySignal::class)->handle($this->event('update', 'edited remotely', 'bafyreinew'));

        $this->assertSame([], $this->outbound);
        $this->assertSame('published: edited remotely', $model->fresh()->content);
        $this->assertSame('bafyreinew', $model->fresh()->atp_cid);
    }

    public function test_a_remote_create_is_applied_without_writing_back(): void
    {
        app(ParitySignal::class)->handle($this->event('create', 'new remotely', 'bafyreinew'));

        $this->assertSame([], $this->outbound);
        $this->assertSame('published: new remotely', AutoSyncModel::first()?->content);
    }

    /**
     * Import and deferred-reference replay call the mapper directly, with no
     * signal around them, so the mapper has to hold the line on its own.
     */
    public function test_upserting_through_the_mapper_alone_does_not_write_back(): void
    {
        $this->syncedModel();

        (new PublishingMapper())->upsert(new TestRecord(text: 'imported'), [
            'uri' => self::URI,
            'cid' => 'bafyreiimported',
            'did' => 'did:plc:test',
        ]);

        $this->assertSame([], $this->outbound);
        $this->assertSame('published: imported', AutoSyncModel::first()->content);
    }

    public function test_an_inbound_reference_does_not_resync_the_pair(): void
    {
        $registry = app(MapperRegistry::class);
        $registry->register(new class () extends TestMainMapper {
            public function modelClass(): string
            {
                return AutoSyncReferenceModel::class;
            }
        });
        $registry->register(new class () extends TestReferenceMapper {
            public function modelClass(): string
            {
                return AutoSyncReferenceModel::class;
            }
        });

        AutoSyncReferenceModel::create([
            'title' => 'Linked',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreimain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.reference/ref1',
            'atp_reference_cid' => 'bafyreiold',
        ]);
        $this->outbound = [];

        $registry->forLexicon('app.test.reference')->upsert(
            TestReferenceRecord::fromArray(['subject' => ['uri' => 'at://did:plc:test/app.test.main/abc', 'cid' => 'bafyreimain']]),
            ['uri' => 'at://did:plc:test/app.test.reference/ref1', 'cid' => 'bafyreinew', 'did' => 'did:plc:test'],
        );

        $this->assertSame([], $this->outbound);
        $this->assertSame('bafyreinew', AutoSyncReferenceModel::first()->atp_reference_cid);
    }

    /**
     * The guard is scoped to the inbound write, not a switch left off behind it.
     */
    public function test_a_local_edit_after_an_inbound_one_still_syncs(): void
    {
        $model = $this->syncedModel();

        app(ParitySignal::class)->handle($this->event('update', 'edited remotely', 'bafyreinew'));

        $model->refresh()->update(['content' => 'edited locally']);

        $this->assertSame(['sync'], $this->outbound);
    }
}
