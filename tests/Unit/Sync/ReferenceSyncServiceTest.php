<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

use Illuminate\Support\Facades\Event;
use Mockery;
use SocialDept\AtpParity\Events\RecordSynced;
use SocialDept\AtpParity\Events\ReferenceSynced;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Sync\ReferenceSyncService;
use SocialDept\AtpParity\Sync\SyncService;
use SocialDept\AtpParity\Tests\Fixtures\ReferenceModel;
use SocialDept\AtpParity\Tests\Fixtures\TestMainMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestReferenceMapper;
use SocialDept\AtpParity\Tests\TestCase;
use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;

class ReferenceSyncServiceTest extends TestCase
{
    private ReferenceSyncService $service;

    private SyncService $syncService;

    private MapperRegistry $registry;

    private TestReferenceMapper $referenceMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new MapperRegistry();
        $this->registry->register(new TestMapper());
        $this->registry->register(new TestMainMapper()); // Main mapper for ReferenceModel
        $this->referenceMapper = new TestReferenceMapper();
        $this->registry->register($this->referenceMapper);

        // Bind registry to container so mainMapper() works correctly
        $this->app->instance(MapperRegistry::class, $this->registry);

        $this->syncService = new SyncService($this->registry);
        $this->service = new ReferenceSyncService($this->registry, $this->syncService);

        Event::fake();
    }

    // ==========================================
    // syncWithReference tests
    // ==========================================

    public function test_sync_with_reference_creates_both_records(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Test',
            'atp_uri' => null,
        ]);

        $this->mockAtpClientForBothRecords(
            'did:plc:test',
            mainUri: 'at://did:plc:test/app.test.main/abc',
            mainCid: 'bafyreiMain',
            refUri: 'at://did:plc:test/app.test.ref/xyz',
            refCid: 'bafyreiRef'
        );

        $result = $this->service->syncWithReference(
            'did:plc:test',
            $model,
            $this->referenceMapper
        );

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->isFullySynced());
        $this->assertSame('at://did:plc:test/app.test.main/abc', $result->mainUri);
        $this->assertSame('bafyreiMain', $result->mainCid);
        $this->assertSame('at://did:plc:test/app.test.ref/xyz', $result->referenceUri);
        $this->assertSame('bafyreiRef', $result->referenceCid);
    }

    public function test_sync_with_reference_fails_when_no_main_mapper(): void
    {
        // Create a mapper without a registered main mapper
        $mapper = new class extends TestReferenceMapper {
            public function mainMapper(): ?\SocialDept\AtpParity\Contracts\RecordMapper
            {
                return null;
            }
        };

        $model = new ReferenceModel(['title' => 'Test']);

        $result = $this->service->syncWithReference('did:plc:test', $model, $mapper);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('No mapper registered', $result->error);
    }

    public function test_sync_with_reference_fails_when_main_sync_fails(): void
    {
        $model = new ReferenceModel(['title' => 'Test']);

        $this->mockAtpClientWithException('did:plc:test', 'Main sync failed');

        $result = $this->service->syncWithReference('did:plc:test', $model, $this->referenceMapper);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('Main sync failed', $result->error);
    }

    public function test_sync_with_reference_rolls_back_main_when_reference_fails(): void
    {
        $model = ReferenceModel::create(['title' => 'Test']);

        $this->mockAtpClientForMainThenReferenceFailure(
            'did:plc:test',
            mainUri: 'at://did:plc:test/app.test.main/abc',
            mainCid: 'bafyreiMain',
            refError: 'Reference creation failed'
        );

        $result = $this->service->syncWithReference(
            'did:plc:test',
            $model,
            $this->referenceMapper,
            rollbackOnFailure: true
        );

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('Reference record failed', $result->error);
        $this->assertStringContainsString('rolled back', $result->error);
    }

    public function test_sync_with_reference_keeps_main_when_rollback_disabled(): void
    {
        $model = ReferenceModel::create(['title' => 'Test']);

        $this->mockAtpClientForMainThenReferenceFailure(
            'did:plc:test',
            mainUri: 'at://did:plc:test/app.test.main/abc',
            mainCid: 'bafyreiMain',
            refError: 'Reference creation failed'
        );

        $result = $this->service->syncWithReference(
            'did:plc:test',
            $model,
            $this->referenceMapper,
            rollbackOnFailure: false
        );

        // Result should be partial success (main synced, reference failed)
        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->hasMainOnly());
        $this->assertSame('at://did:plc:test/app.test.main/abc', $result->mainUri);
        $this->assertNull($result->referenceUri);
    }

    public function test_sync_with_reference_dispatches_events(): void
    {
        $model = ReferenceModel::create(['title' => 'Test']);

        $this->mockAtpClientForBothRecords(
            'did:plc:test',
            mainUri: 'at://did:plc:test/app.test.main/abc',
            mainCid: 'bafyreiMain',
            refUri: 'at://did:plc:test/app.test.ref/xyz',
            refCid: 'bafyreiRef'
        );

        $this->service->syncWithReference('did:plc:test', $model, $this->referenceMapper);

        Event::assertDispatched(RecordSynced::class);
        Event::assertDispatched(ReferenceSynced::class);
    }

    // ==========================================
    // syncReferenceOnly tests
    // ==========================================

    public function test_sync_reference_only_creates_reference_record(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Test',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
        ]);

        $this->mockAtpClient('did:plc:test', 'at://did:plc:test/app.test.ref/xyz', 'bafyreiRef');

        $result = $this->service->syncReferenceOnly('did:plc:test', $model, $this->referenceMapper);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('at://did:plc:test/app.test.ref/xyz', $result->uri);
        $this->assertSame('bafyreiRef', $result->cid);
    }

    public function test_sync_reference_only_fails_when_no_main_uri(): void
    {
        $model = new ReferenceModel(['title' => 'Test']);

        $result = $this->service->syncReferenceOnly('did:plc:test', $model, $this->referenceMapper);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('main record', $result->error);
    }

    public function test_sync_reference_only_resyncs_when_already_exists(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Test',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/existing',
            'atp_reference_cid' => 'bafyreiOld',
        ]);

        $this->mockAtpClientForUpdate('did:plc:test', 'at://did:plc:test/app.test.ref/existing', 'bafyreiNew');

        $result = $this->service->syncReferenceOnly('did:plc:test', $model, $this->referenceMapper);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('bafyreiNew', $result->cid);
    }

    public function test_sync_reference_only_dispatches_reference_synced_event(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Test',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
        ]);

        $this->mockAtpClient('did:plc:test', 'at://did:plc:test/app.test.ref/xyz', 'bafyreiRef');

        $this->service->syncReferenceOnly('did:plc:test', $model, $this->referenceMapper);

        Event::assertDispatched(ReferenceSynced::class, function ($event) use ($model) {
            return $event->model->is($model)
                && $event->referenceUri === 'at://did:plc:test/app.test.ref/xyz'
                && $event->mainUri === 'at://did:plc:test/app.test.main/abc';
        });
    }

    // ==========================================
    // syncReferenceToExternal tests
    // ==========================================

    public function test_sync_reference_to_external_sets_main_ref_and_syncs(): void
    {
        $model = ReferenceModel::create(['title' => 'Test']);

        $mainRef = new StrongRef(
            uri: 'at://did:plc:external/site.standard.publication/abc',
            cid: 'bafyreiExternal'
        );

        $this->mockAtpClient('did:plc:test', 'at://did:plc:test/app.test.ref/xyz', 'bafyreiRef');

        $result = $this->service->syncReferenceToExternal(
            'did:plc:test',
            $model,
            $this->referenceMapper,
            $mainRef
        );

        $this->assertTrue($result->isSuccess());

        $model->refresh();
        $this->assertSame('at://did:plc:external/site.standard.publication/abc', $model->atp_uri);
        $this->assertSame('bafyreiExternal', $model->atp_cid);
    }

    public function test_sync_reference_to_external_uses_provided_strong_ref(): void
    {
        $model = ReferenceModel::create(['title' => 'Test']);

        $mainRef = new StrongRef(
            uri: 'at://did:plc:thirdparty/com.other.record/123',
            cid: 'bafyreiThirdParty'
        );

        $this->mockAtpClient('did:plc:test', 'at://did:plc:test/app.test.ref/xyz', 'bafyreiRef');

        $this->service->syncReferenceToExternal('did:plc:test', $model, $this->referenceMapper, $mainRef);

        $model->refresh();
        $this->assertSame('at://did:plc:thirdparty/com.other.record/123', $model->atp_uri);
    }

    // ==========================================
    // resyncReference tests
    // ==========================================

    public function test_resync_reference_updates_existing_record(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Updated',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/existing',
            'atp_reference_cid' => 'bafyreiOld',
        ]);

        $this->mockAtpClientForUpdate('did:plc:test', 'at://did:plc:test/app.test.ref/existing', 'bafyreiUpdated');

        $result = $this->service->resyncReference($model, $this->referenceMapper);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('bafyreiUpdated', $result->cid);

        $model->refresh();
        $this->assertSame('bafyreiUpdated', $model->atp_reference_cid);
    }

    public function test_resync_reference_fails_when_not_synced(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Test',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
        ]);

        $result = $this->service->resyncReference($model, $this->referenceMapper);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('not been synced', $result->error);
    }

    // ==========================================
    // unsyncWithReference tests
    // ==========================================

    public function test_unsync_with_reference_deletes_both_records(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Test',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/xyz',
            'atp_reference_cid' => 'bafyreiRef',
        ]);

        $this->mockAtpClientForMultipleDeletes('did:plc:test');

        $result = $this->service->unsyncWithReference($model, $this->referenceMapper);

        $this->assertTrue($result);

        $model->refresh();
        $this->assertNull($model->atp_uri);
        $this->assertNull($model->atp_cid);
        $this->assertNull($model->atp_reference_uri);
        $this->assertNull($model->atp_reference_cid);
    }

    public function test_unsync_with_reference_deletes_reference_first(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Test',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.reference/xyz',
            'atp_reference_cid' => 'bafyreiRef',
        ]);

        $deletedCollections = [];

        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('deleteRecord')
            ->andReturnUsing(function ($collection, $rkey) use (&$deletedCollections) {
                $deletedCollections[] = $collection;

                return null;
            });

        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with('did:plc:test')
            ->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);

        $result = $this->service->unsyncWithReference($model, $this->referenceMapper);

        $this->assertTrue($result);
        // Reference should be deleted first (app.test.reference), then main (app.test.main)
        $this->assertCount(2, $deletedCollections);
        $this->assertSame('app.test.reference', $deletedCollections[0]);
        $this->assertSame('app.test.main', $deletedCollections[1]);
    }

    // ==========================================
    // unsyncReference tests
    // ==========================================

    public function test_unsync_reference_deletes_only_reference(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Test',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/xyz',
            'atp_reference_cid' => 'bafyreiRef',
        ]);

        $this->mockAtpClientForDelete('did:plc:test');

        $result = $this->service->unsyncReference($model, $this->referenceMapper);

        $this->assertTrue($result);

        $model->refresh();
        $this->assertNull($model->atp_reference_uri);
        $this->assertNull($model->atp_reference_cid);
    }

    public function test_unsync_reference_keeps_main_record(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Test',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/xyz',
            'atp_reference_cid' => 'bafyreiRef',
        ]);

        $this->mockAtpClientForDelete('did:plc:test');

        $this->service->unsyncReference($model, $this->referenceMapper);

        $model->refresh();
        $this->assertSame('at://did:plc:test/app.test.main/abc', $model->atp_uri);
        $this->assertSame('bafyreiMain', $model->atp_cid);
    }

    public function test_unsync_reference_returns_false_when_not_synced(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Test',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
        ]);

        $result = $this->service->unsyncReference($model, $this->referenceMapper);

        $this->assertFalse($result);
    }

    // ==========================================
    // Helper methods
    // ==========================================

    protected function mockAtpClient(string $did, string $returnUri, string $returnCid): void
    {
        $response = new \stdClass();
        $response->uri = $returnUri;
        $response->cid = $returnCid;

        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('createRecord')
            ->andReturn($response);

        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with($did)
            ->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    protected function mockAtpClientForUpdate(string $did, string $returnUri, string $returnCid): void
    {
        $response = new \stdClass();
        $response->uri = $returnUri;
        $response->cid = $returnCid;

        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('putRecord')
            ->andReturn($response);

        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with($did)
            ->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    protected function mockAtpClientForDelete(string $did): void
    {
        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('deleteRecord')
            ->andReturnNull();

        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with($did)
            ->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    protected function mockAtpClientForMultipleDeletes(string $did): void
    {
        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('deleteRecord')
            ->twice()
            ->andReturnNull();

        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with($did)
            ->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    protected function mockAtpClientForBothRecords(
        string $did,
        string $mainUri,
        string $mainCid,
        string $refUri,
        string $refCid
    ): void {
        $mainResponse = new \stdClass();
        $mainResponse->uri = $mainUri;
        $mainResponse->cid = $mainCid;

        $refResponse = new \stdClass();
        $refResponse->uri = $refUri;
        $refResponse->cid = $refCid;

        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('createRecord')
            ->andReturn($mainResponse, $refResponse);

        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with($did)
            ->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    protected function mockAtpClientForMainThenReferenceFailure(
        string $did,
        string $mainUri,
        string $mainCid,
        string $refError
    ): void {
        $mainResponse = new \stdClass();
        $mainResponse->uri = $mainUri;
        $mainResponse->cid = $mainCid;

        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('createRecord')
            ->once()
            ->andReturn($mainResponse);
        $repoClient->shouldReceive('createRecord')
            ->once()
            ->andThrow(new \Exception($refError));
        $repoClient->shouldReceive('deleteRecord')
            ->andReturnNull();

        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with($did)
            ->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    protected function mockAtpClientWithException(string $did, string $message): void
    {
        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with($did)
            ->andThrow(new \Exception($message));

        $this->app->instance('atp-client', $manager);
    }
}
