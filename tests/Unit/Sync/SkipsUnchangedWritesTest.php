<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Mockery;
use SocialDept\AtpParity\Events\RecordSynced;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Support\RecordCid;
use SocialDept\AtpParity\Sync\SyncService;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * A resync must not write a record the repo already holds byte for byte.
 *
 * Writing an identical record still produces a signed commit and a firehose event
 * on the author's PDS, so a sync that fires on every model save bills a repo we do
 * not own for work that changes nothing.
 *
 * Note what the mock returns: the **real** content CID, because that is what a PDS
 * returns. The guard compares against the CID it stored last time, so it only
 * engages when that stored value is genuinely the record's address. A PDS that
 * returned something else would simply never match, which is the safe direction.
 */
class SkipsUnchangedWritesTest extends TestCase
{
    private SyncService $service;

    private int $writes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = new MapperRegistry();
        $registry->register(new TestMapper());

        $this->service = new SyncService($registry);
        $this->writes = 0;

        Event::fake();
    }

    private function syncedModel(string $content = 'Hello world'): TestModel
    {
        $model = new TestModel([
            'content' => $content,
            'atp_uri' => 'at://did:plc:test123/app.test.record/abc',
        ]);

        // Pinned so the record, and therefore its CID, is stable across runs.
        $model->created_at = Carbon::parse('2026-01-01T00:00:00Z');
        $model->save();

        return $model;
    }

    private function contentCidFor(TestModel $model): string
    {
        return RecordCid::for((new TestMapper())->toRecord($model)->toRecord());
    }

    /**
     * Counts putRecord calls and answers with the CID a PDS would compute.
     */
    private function mockPds(TestModel $model): void
    {
        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('putRecord')->andReturnUsing(function (...$args) use ($model) {
            $this->writes++;

            $response = new \stdClass();
            $response->uri = $model->atp_uri;
            $response->cid = $this->contentCidFor($model);

            return $response;
        });

        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        $manager = Mockery::mock();
        $manager->shouldReceive('as')->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    public function test_it_does_not_write_a_record_the_repo_already_holds(): void
    {
        $model = $this->syncedModel();
        $model->atp_cid = $this->contentCidFor($model);
        $model->saveQuietly();

        $this->mockPds($model);

        $result = $this->service->resync($model);

        $this->assertSame(0, $this->writes);
        $this->assertTrue($result->isSuccess(), 'An unchanged record is in the desired state, so this is a success.');
        $this->assertTrue($result->unchanged);
    }

    /**
     * The runaway case in miniature: nothing changes between passes, so only the
     * first may reach the repo.
     */
    public function test_repeated_resyncs_of_an_unchanged_model_write_once(): void
    {
        $model = $this->syncedModel();
        $this->mockPds($model);

        for ($i = 0; $i < 5; $i++) {
            $this->service->resync($model);
        }

        $this->assertSame(1, $this->writes);
    }

    public function test_it_still_writes_when_the_record_changed(): void
    {
        $model = $this->syncedModel();
        $model->atp_cid = $this->contentCidFor($model);
        $model->saveQuietly();

        $this->mockPds($model);

        $model->content = 'Genuinely different';

        $result = $this->service->resync($model);

        $this->assertSame(1, $this->writes, 'A real edit must reach the repo.');
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->unchanged);
    }

    public function test_it_writes_when_no_cid_has_been_stored(): void
    {
        $model = $this->syncedModel();
        $model->atp_cid = null;
        $model->saveQuietly();

        $this->mockPds($model);

        $this->service->resync($model);

        $this->assertSame(1, $this->writes);
    }

    /**
     * A stored CID from some other source must never suppress a write.
     */
    public function test_it_writes_when_the_stored_cid_is_not_the_records_own(): void
    {
        $model = $this->syncedModel();
        $model->atp_cid = 'bafyreiatlpb3fy7y7556rjuw3txfyah6duil3c5um72loqyp7lhcstgvcy';
        $model->saveQuietly();

        $this->mockPds($model);

        $this->service->resync($model);

        $this->assertSame(1, $this->writes);
    }

    public function test_the_kill_switch_restores_unconditional_writing(): void
    {
        config(['atp-parity.sync.skip_unchanged' => false]);

        $model = $this->syncedModel();
        $model->atp_cid = $this->contentCidFor($model);
        $model->saveQuietly();

        $this->mockPds($model);

        $this->service->resync($model);

        $this->assertSame(1, $this->writes);
    }

    /**
     * An operator repairing a repo must be able to write regardless. An equal CID
     * proves what we last wrote, not what the repo still holds, so a record lost
     * out of band is invisible to the guard and only a forced write restores it.
     */
    public function test_force_writes_even_when_the_record_looks_unchanged(): void
    {
        $model = $this->syncedModel();
        $model->atp_cid = $this->contentCidFor($model);
        $model->saveQuietly();

        $this->mockPds($model);

        $result = $this->service->resync($model, force: true);

        $this->assertSame(1, $this->writes);
        $this->assertFalse($result->unchanged);
    }

    /**
     * Nothing was written, so nothing should tell listeners a record was synced.
     * Consumers act on this event, including ones that reclaim local storage.
     */
    public function test_a_skipped_write_dispatches_no_synced_event(): void
    {
        $model = $this->syncedModel();
        $model->atp_cid = $this->contentCidFor($model);
        $model->saveQuietly();

        $this->mockPds($model);

        $this->service->resync($model);

        Event::assertNotDispatched(RecordSynced::class);
    }
}
