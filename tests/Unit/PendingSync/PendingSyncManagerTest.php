<?php

namespace SocialDept\AtpParity\Tests\Unit\PendingSync;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use SocialDept\AtpClient\Exceptions\AuthenticationException;
use SocialDept\AtpClient\Exceptions\OAuthSessionInvalidException;
use SocialDept\AtpParity\Contracts\ReferenceMapper;
use SocialDept\AtpParity\Enums\PendingSyncOperation;
use SocialDept\AtpParity\Events\PendingSyncCaptured;
use SocialDept\AtpParity\Events\PendingSyncFailed;
use SocialDept\AtpParity\Events\PendingSyncRetried;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\PendingSync\CachePendingSyncStore;
use SocialDept\AtpParity\PendingSync\PendingSyncManager;
use SocialDept\AtpParity\Sync\ReferenceSyncService;
use SocialDept\AtpParity\Sync\SyncResult;
use SocialDept\AtpParity\Sync\SyncService;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;

class PendingSyncManagerTest extends TestCase
{
    private PendingSyncManager $manager;

    private MockInterface $syncService;

    private MockInterface $referenceSyncService;

    private MockInterface $registry;

    private CachePendingSyncStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new CachePendingSyncStore(Cache::store('array'), 3600);
        $this->syncService = Mockery::mock(SyncService::class);
        $this->referenceSyncService = Mockery::mock(ReferenceSyncService::class);
        $this->registry = Mockery::mock(MapperRegistry::class);

        $this->manager = new PendingSyncManager(
            $this->store,
            $this->syncService,
            $this->referenceSyncService,
            $this->registry,
        );
    }

    public function test_is_enabled_returns_false_by_default(): void
    {
        $this->assertFalse($this->manager->isEnabled());
    }

    public function test_is_enabled_returns_true_when_configured(): void
    {
        $this->app['config']->set('parity.pending_syncs.enabled', true);

        $this->assertTrue($this->manager->isEnabled());
    }

    public function test_capture_stores_pending_sync(): void
    {
        Event::fake([PendingSyncCaptured::class]);

        $model = TestModel::create(['content' => 'Test']);

        $pendingSync = $this->manager->capture(
            'did:plc:test',
            $model,
            PendingSyncOperation::Sync,
        );

        $this->assertSame('did:plc:test', $pendingSync->did);
        $this->assertSame(TestModel::class, $pendingSync->modelClass);
        $this->assertSame($model->id, $pendingSync->modelId);
        $this->assertSame(PendingSyncOperation::Sync, $pendingSync->operation);
        $this->assertNull($pendingSync->referenceMapperClass);
        $this->assertSame(0, $pendingSync->attempts);

        Event::assertDispatched(PendingSyncCaptured::class);
    }

    public function test_capture_with_reference_mapper(): void
    {
        Event::fake([PendingSyncCaptured::class]);

        $model = TestModel::create(['content' => 'Test']);
        $mapper = Mockery::mock(ReferenceMapper::class);

        $pendingSync = $this->manager->capture(
            'did:plc:test',
            $model,
            PendingSyncOperation::SyncWithReference,
            $mapper,
        );

        $this->assertSame(PendingSyncOperation::SyncWithReference, $pendingSync->operation);
        $this->assertSame(get_class($mapper), $pendingSync->referenceMapperClass);
    }

    public function test_capture_replaces_existing_for_same_model(): void
    {
        $model = TestModel::create(['content' => 'Test']);

        $first = $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Sync);
        $second = $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Resync);

        $this->assertSame(1, $this->manager->countForDid('did:plc:test'));

        $pending = $this->manager->forDid('did:plc:test');
        $this->assertSame(PendingSyncOperation::Resync, $pending[0]->operation);
    }

    public function test_retry_for_did_succeeds(): void
    {
        Event::fake([PendingSyncCaptured::class, PendingSyncRetried::class]);

        $model = TestModel::create(['content' => 'Test']);

        $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Sync);

        $this->syncService
            ->shouldReceive('syncAs')
            ->once()
            ->with('did:plc:test', Mockery::type(TestModel::class))
            ->andReturn(SyncResult::success('at://did/collection/rkey', 'bafy123'));

        $result = $this->manager->retryForDid('did:plc:test');

        $this->assertSame(1, $result->total);
        $this->assertSame(1, $result->succeeded);
        $this->assertSame(0, $result->failed);
        $this->assertTrue($result->allSucceeded());

        // Pending sync should be removed after success
        $this->assertSame(0, $this->manager->countForDid('did:plc:test'));

        Event::assertDispatched(PendingSyncRetried::class, function ($event) {
            return $event->success === true;
        });
    }

    public function test_retry_for_did_handles_failure(): void
    {
        Event::fake([PendingSyncCaptured::class, PendingSyncRetried::class]);

        $model = TestModel::create(['content' => 'Test']);

        $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Sync);

        $this->syncService
            ->shouldReceive('syncAs')
            ->once()
            ->andReturn(SyncResult::failed('Network error'));

        $result = $this->manager->retryForDid('did:plc:test');

        $this->assertSame(1, $result->total);
        $this->assertSame(0, $result->succeeded);
        $this->assertSame(1, $result->failed);
        $this->assertTrue($result->hasFailures());

        // Pending sync should remain for retry
        $this->assertSame(1, $this->manager->countForDid('did:plc:test'));
    }

    public function test_retry_skips_when_max_attempts_exceeded(): void
    {
        Event::fake([PendingSyncCaptured::class]);

        $model = TestModel::create(['content' => 'Test']);
        $this->app['config']->set('parity.pending_syncs.max_attempts', 2);

        $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Sync);

        // Simulate previous failed attempts
        $this->syncService
            ->shouldReceive('syncAs')
            ->twice()
            ->andReturn(SyncResult::failed('Error'));

        $this->manager->retryForDid('did:plc:test'); // attempt 1
        $this->manager->retryForDid('did:plc:test'); // attempt 2

        // Third retry should skip (max_attempts = 2)
        $result = $this->manager->retryForDid('did:plc:test');

        $this->assertSame(1, $result->total);
        $this->assertSame(0, $result->succeeded);
        $this->assertSame(0, $result->failed);
        $this->assertSame(1, $result->skipped);

        // Should be removed after being skipped
        $this->assertSame(0, $this->manager->countForDid('did:plc:test'));
    }

    public function test_retry_handles_deleted_model(): void
    {
        Event::fake([PendingSyncCaptured::class, PendingSyncRetried::class]);

        $model = TestModel::create(['content' => 'Test']);
        $modelId = $model->id;

        $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Sync);

        // Delete the model before retry
        $model->delete();

        $result = $this->manager->retryForDid('did:plc:test');

        $this->assertSame(1, $result->succeeded);
        $this->assertSame(0, $this->manager->countForDid('did:plc:test'));
    }

    public function test_retry_bubbles_authentication_exception(): void
    {
        Event::fake([PendingSyncCaptured::class]);

        $model = TestModel::create(['content' => 'Test']);

        $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Sync);

        $this->syncService
            ->shouldReceive('syncAs')
            ->once()
            ->andThrow(new AuthenticationException('Invalid credentials'));

        $this->expectException(AuthenticationException::class);

        $this->manager->retryForDid('did:plc:test');
    }

    public function test_retry_bubbles_oauth_session_invalid_exception(): void
    {
        if (! class_exists(OAuthSessionInvalidException::class)) {
            $this->markTestSkipped('OAuthSessionInvalidException not available in installed atp-client version');
        }

        Event::fake([PendingSyncCaptured::class]);

        $model = TestModel::create(['content' => 'Test']);

        $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Sync);

        $this->syncService
            ->shouldReceive('syncAs')
            ->once()
            ->andThrow(new OAuthSessionInvalidException('Session expired'));

        $this->expectException(OAuthSessionInvalidException::class);

        $this->manager->retryForDid('did:plc:test');
    }

    public function test_retry_catches_other_exceptions(): void
    {
        Event::fake([PendingSyncCaptured::class, PendingSyncFailed::class]);

        $model = TestModel::create(['content' => 'Test']);

        $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Sync);

        $this->syncService
            ->shouldReceive('syncAs')
            ->once()
            ->andThrow(new \RuntimeException('Something went wrong'));

        $result = $this->manager->retryForDid('did:plc:test');

        $this->assertSame(1, $result->failed);
        $this->assertContains('Something went wrong', $result->errors);

        Event::assertDispatched(PendingSyncFailed::class);
    }

    public function test_retry_resync_operation(): void
    {
        Event::fake([PendingSyncCaptured::class, PendingSyncRetried::class]);

        $model = TestModel::create([
            'content' => 'Test',
            'atp_uri' => 'at://did/collection/rkey',
            'atp_cid' => 'old-cid',
        ]);

        $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Resync);

        $this->syncService
            ->shouldReceive('resync')
            ->once()
            ->with(Mockery::type(TestModel::class))
            ->andReturn(SyncResult::success('at://did/collection/rkey', 'new-cid'));

        $result = $this->manager->retryForDid('did:plc:test');

        $this->assertSame(1, $result->succeeded);
    }

    public function test_retry_unsync_operation(): void
    {
        Event::fake([PendingSyncCaptured::class, PendingSyncRetried::class]);

        $model = TestModel::create([
            'content' => 'Test',
            'atp_uri' => 'at://did/collection/rkey',
            'atp_cid' => 'cid',
        ]);

        $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Unsync);

        $this->syncService
            ->shouldReceive('unsync')
            ->once()
            ->with(Mockery::type(TestModel::class))
            ->andReturn(true);

        $result = $this->manager->retryForDid('did:plc:test');

        $this->assertSame(1, $result->succeeded);
    }

    public function test_has_pending_syncs(): void
    {
        $this->assertFalse($this->manager->hasPendingSyncs('did:plc:test'));

        $model = TestModel::create(['content' => 'Test']);
        $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Sync);

        $this->assertTrue($this->manager->hasPendingSyncs('did:plc:test'));
    }

    public function test_count_for_did(): void
    {
        $this->assertSame(0, $this->manager->countForDid('did:plc:test'));

        $model1 = TestModel::create(['content' => 'Test 1']);
        $model2 = TestModel::create(['content' => 'Test 2']);

        $this->manager->capture('did:plc:test', $model1, PendingSyncOperation::Sync);
        $this->manager->capture('did:plc:test', $model2, PendingSyncOperation::Sync);

        $this->assertSame(2, $this->manager->countForDid('did:plc:test'));
    }

    public function test_for_did_returns_pending_syncs(): void
    {
        $model = TestModel::create(['content' => 'Test']);
        $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Sync);

        $pending = $this->manager->forDid('did:plc:test');

        $this->assertCount(1, $pending);
        $this->assertSame(TestModel::class, $pending[0]->modelClass);
    }

    public function test_remove_pending_sync(): void
    {
        $model = TestModel::create(['content' => 'Test']);
        $pendingSync = $this->manager->capture('did:plc:test', $model, PendingSyncOperation::Sync);

        $this->assertSame(1, $this->manager->countForDid('did:plc:test'));

        $this->manager->remove($pendingSync->id);

        $this->assertSame(0, $this->manager->countForDid('did:plc:test'));
    }

    public function test_remove_for_did(): void
    {
        $model1 = TestModel::create(['content' => 'Test 1']);
        $model2 = TestModel::create(['content' => 'Test 2']);

        $this->manager->capture('did:plc:test', $model1, PendingSyncOperation::Sync);
        $this->manager->capture('did:plc:test', $model2, PendingSyncOperation::Sync);

        $removed = $this->manager->removeForDid('did:plc:test');

        $this->assertSame(2, $removed);
        $this->assertSame(0, $this->manager->countForDid('did:plc:test'));
    }

    public function test_retry_multiple_pending_syncs(): void
    {
        Event::fake([PendingSyncCaptured::class, PendingSyncRetried::class]);

        $model1 = TestModel::create(['content' => 'Test 1']);
        $model2 = TestModel::create(['content' => 'Test 2']);
        $model3 = TestModel::create(['content' => 'Test 3']);

        $this->manager->capture('did:plc:test', $model1, PendingSyncOperation::Sync);
        $this->manager->capture('did:plc:test', $model2, PendingSyncOperation::Sync);
        $this->manager->capture('did:plc:test', $model3, PendingSyncOperation::Sync);

        $this->syncService
            ->shouldReceive('syncAs')
            ->times(3)
            ->andReturn(SyncResult::success('at://did/collection/rkey', 'cid'));

        $result = $this->manager->retryForDid('did:plc:test');

        $this->assertSame(3, $result->total);
        $this->assertSame(3, $result->succeeded);
        $this->assertTrue($result->allSucceeded());
    }

    public function test_retry_for_did_with_no_pending_syncs(): void
    {
        $result = $this->manager->retryForDid('did:plc:nonexistent');

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->total);
    }
}
