<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Sync\ReferenceSyncService;
use SocialDept\AtpParity\Sync\SyncService;
use SocialDept\AtpParity\Tests\Fixtures\ReferenceModel;
use SocialDept\AtpParity\Tests\Fixtures\TestMainMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\Fixtures\TestReferenceMapper;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * The CID a write returns has to reach the row, whatever the instance last read.
 *
 * The metadata was written with a save, and a save writes only what differs from
 * the instance's original. When another process had moved the row on and the
 * write returned the value this instance started with, nothing was written: the
 * row kept a CID the repo no longer held, and the unchanged-write guard compared
 * against it from then on.
 */
class MetaColumnsTest extends TestCase
{
    private const MAIN_URI = 'at://did:plc:test/app.test.record/abc';

    private const REFERENCE_URI = 'at://did:plc:test/app.test.reference/ref1';

    private MapperRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new MapperRegistry();
        $this->registry->register(new TestMapper());
        $this->registry->register(new TestMainMapper());
        $this->registry->register(new TestReferenceMapper());
        $this->app->instance(MapperRegistry::class, $this->registry);

        Event::fake();
    }

    private function pdsAnswering(string $uri, string $cid): void
    {
        $response = new \stdClass();
        $response->uri = $uri;
        $response->cid = $cid;

        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('putRecord')->andReturn($response);

        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        $manager = Mockery::mock();
        $manager->shouldReceive('as')->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    public function test_a_stale_instance_still_writes_the_returned_cid_to_the_row(): void
    {
        $stale = TestModel::create([
            'content' => 'Hello',
            'atp_uri' => self::MAIN_URI,
            'atp_cid' => 'bafyreiours',
        ]);

        DB::table('test_models')->where('id', $stale->id)->update(['atp_cid' => 'bafyreisomeoneelse']);

        $this->pdsAnswering(self::MAIN_URI, 'bafyreiours');

        (new SyncService($this->registry))->resync($stale);

        $this->assertSame('bafyreiours', DB::table('test_models')->value('atp_cid'));
        $this->assertFalse($stale->isDirty('atp_cid'));
    }

    public function test_a_stale_instance_still_writes_the_returned_reference_cid_to_the_row(): void
    {
        $stale = ReferenceModel::create([
            'title' => 'Linked',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreimain',
            'atp_reference_uri' => self::REFERENCE_URI,
            'atp_reference_cid' => 'bafyreiours',
        ]);

        DB::table('reference_models')->where('id', $stale->id)->update(['atp_reference_cid' => 'bafyreisomeoneelse']);

        $this->pdsAnswering(self::REFERENCE_URI, 'bafyreiours');

        $service = new ReferenceSyncService($this->registry, new SyncService($this->registry));
        $service->resyncReference($stale, new TestReferenceMapper());

        $this->assertSame('bafyreiours', DB::table('reference_models')->value('atp_reference_cid'));
    }

    /**
     * `updated_at` moves exactly as the save did, so "changed since last sync"
     * (updated_at after atp_synced_at) reads the same as before.
     */
    public function test_updated_at_moves_with_the_metadata_as_a_save_moved_it(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $model = TestModel::create([
            'content' => 'Hello',
            'atp_uri' => self::MAIN_URI,
            'atp_cid' => 'bafyreiprevious',
        ]);

        Carbon::setTestNow('2026-01-02 00:00:00');

        $this->pdsAnswering(self::MAIN_URI, 'bafyreinew');

        (new SyncService($this->registry))->resync($model);

        $row = DB::table('test_models')->first();

        $this->assertSame('2026-01-02 00:00:00', $row->updated_at);
        $this->assertSame($row->updated_at, $row->atp_synced_at);

        Carbon::setTestNow();
    }

    public function test_other_unsaved_changes_on_the_instance_are_still_saved(): void
    {
        $model = TestModel::create([
            'content' => 'Hello',
            'atp_uri' => self::MAIN_URI,
            'atp_cid' => 'bafyreiprevious',
        ]);

        $model->content = 'Edited before syncing';

        $this->pdsAnswering(self::MAIN_URI, 'bafyreinew');

        (new SyncService($this->registry))->resync($model);

        $this->assertSame('Edited before syncing', DB::table('test_models')->value('content'));
        $this->assertFalse($model->isDirty());
    }
}
