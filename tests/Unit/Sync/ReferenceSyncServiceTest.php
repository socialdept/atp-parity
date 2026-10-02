<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

use Illuminate\Support\Facades\Event;
use Mockery;
use SocialDept\AtpParity\Events\RecordSynced;
use SocialDept\AtpParity\Events\ReferenceSynced;
use SocialDept\AtpParity\Events\ReferenceSyncFailed;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Support\RecordCid;
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
        $mapper = new class () extends TestReferenceMapper {
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

    public function test_sync_with_reference_surfaces_reference_failure_when_rollback_disabled(): void
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

        // The reference failure is no longer hidden behind a clean success.
        $this->assertTrue($result->hasReferenceFailure());
        $this->assertStringContainsString('Reference creation failed', $result->referenceError);
        Event::assertDispatched(ReferenceSyncFailed::class);
    }

    public function test_resync_with_reference_surfaces_reference_failure_and_keeps_stale_reference(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Updated',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMainOld',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/existing',
            'atp_reference_cid' => 'bafyreiRefOld',
        ]);

        $this->mockAtpClientForReferenceUpdateFailure(
            'did:plc:test',
            mainUri: 'at://did:plc:test/app.test.main/abc',
            mainCid: 'bafyreiMainNew',
            refError: 'PDS unavailable'
        );

        $result = $this->service->resyncWithReference($model, $this->referenceMapper);

        // Main synced; the reference is surfaced as failed, not a fake success.
        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->hasReferenceFailure());
        $this->assertStringContainsString('PDS unavailable', $result->referenceError);
        $this->assertFalse($result->isFullySynced());

        // The stale reference is retained and its CID is not overwritten.
        $this->assertSame('at://did:plc:test/app.test.ref/existing', $result->referenceUri);
        $model->refresh();
        $this->assertSame('bafyreiRefOld', $model->atp_reference_cid);

        Event::assertDispatched(ReferenceSyncFailed::class, function ($event) use ($model) {
            return $event->model->is($model)
                && $event->referenceUri === 'at://did:plc:test/app.test.ref/existing';
        });
        Event::assertNotDispatched(ReferenceSynced::class);
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

    /**
     * Counts writes through Mockery itself rather than a closure, so an expectation
     * that is never met fails at teardown instead of silently passing.
     */
    private function mockPdsExpectingWrites(int $times, string $did, string $returnUri, string $returnCid): void
    {
        $response = new \stdClass();
        $response->uri = $returnUri;
        $response->cid = $returnCid;

        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('putRecord')->times($times)->andReturn($response);

        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        $manager = Mockery::mock();
        $manager->shouldReceive('as')->with($did)->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    /**
     * The reference record is the second half of a pair that is rewritten together,
     * so it needs the same guard as the main record, keyed on its own CID column.
     */
    public function test_resync_reference_does_not_write_a_record_the_repo_already_holds(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Unchanged',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/existing',
        ]);

        $contentCid = RecordCid::for($this->referenceMapper->toRecord($model)->toArray());
        $model->atp_reference_cid = $contentCid;
        $model->saveQuietly();

        $this->mockPdsExpectingWrites(0, 'did:plc:test', 'at://did:plc:test/app.test.ref/existing', $contentCid);

        $result = $this->service->resyncReference($model, $this->referenceMapper);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->unchanged);
        Event::assertNotDispatched(ReferenceSynced::class);
    }

    /**
     * A reference record holds a StrongRef to the main record and nothing else, so
     * the only thing that can change it is the main record's uri or cid. Editing a
     * column the reference does not carry must not produce a write, which is why
     * this moves `atp_cid` rather than a title.
     */
    public function test_resync_reference_still_writes_when_the_subject_changed(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Before',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/existing',
        ]);

        $model->atp_reference_cid = RecordCid::for($this->referenceMapper->toRecord($model)->toArray());
        $model->saveQuietly();

        $this->mockPdsExpectingWrites(1, 'did:plc:test', 'at://did:plc:test/app.test.ref/existing', 'bafyreiUpdated');

        // The main record moved, so the reference genuinely points somewhere new.
        $model->atp_cid = 'bafyreiMainMovedOn';

        $result = $this->service->resyncReference($model, $this->referenceMapper);

        $this->assertFalse($result->unchanged);
    }

    /**
     * Editing a column the reference record does not carry is the common case: a
     * model save that changes nothing in the record would otherwise rewrite both
     * halves of the pair.
     */
    public function test_resync_reference_does_not_write_when_an_uncarried_column_changed(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Before',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/existing',
        ]);

        $model->atp_reference_cid = RecordCid::for($this->referenceMapper->toRecord($model)->toArray());
        $model->saveQuietly();

        $this->mockPdsExpectingWrites(0, 'did:plc:test', 'at://did:plc:test/app.test.ref/existing', 'bafyreiUnused');

        $model->title = 'A title the reference record does not carry';

        $this->assertTrue($this->service->resyncReference($model, $this->referenceMapper)->unchanged);
    }

    /**
     * `resyncWithReference()` is what a repair tool calls, so a force that reached
     * only `resyncReference()` would leave the pair unrepairable. Both halves must
     * write: two putRecord calls, not zero and not one.
     */
    public function test_force_on_the_combined_resync_writes_both_records(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Unchanged',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/existing',
        ]);

        // Both records already match what we would write, so nothing is due.
        $model->atp_cid = RecordCid::for($this->registry->forLexicon('app.test.main')->toRecord($model)->toArray());
        $model->atp_reference_cid = RecordCid::for($this->referenceMapper->toRecord($model)->toArray());
        $model->saveQuietly();

        $this->mockPdsExpectingWrites(2, 'did:plc:test', 'at://did:plc:test/app.test.ref/existing', 'bafyreiForced');

        $this->service->resyncWithReference($model, $this->referenceMapper, force: true);
    }

    /**
     * The same pair without force must not write at all, which is what makes the
     * test above meaningful rather than tautological.
     */
    public function test_the_combined_resync_writes_neither_record_when_both_are_unchanged(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Unchanged',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafyreiMain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.ref/existing',
        ]);

        $model->atp_cid = RecordCid::for($this->registry->forLexicon('app.test.main')->toRecord($model)->toArray());
        $model->atp_reference_cid = RecordCid::for($this->referenceMapper->toRecord($model)->toArray());
        $model->saveQuietly();

        $this->mockPdsExpectingWrites(0, 'did:plc:test', 'at://did:plc:test/app.test.ref/existing', 'bafyreiUnused');

        $this->service->resyncWithReference($model, $this->referenceMapper);
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

    protected function mockAtpClientForReferenceUpdateFailure(
        string $did,
        string $mainUri,
        string $mainCid,
        string $refError
    ): void {
        $mainResponse = new \stdClass();
        $mainResponse->uri = $mainUri;
        $mainResponse->cid = $mainCid;

        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('putRecord')
            ->once()
            ->andReturn($mainResponse);
        $repoClient->shouldReceive('putRecord')
            ->once()
            ->andThrow(new \Exception($refError));

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
