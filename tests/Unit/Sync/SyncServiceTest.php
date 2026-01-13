<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

use Illuminate\Support\Facades\Event;
use Mockery;
use SocialDept\AtpParity\Events\RecordSynced;
use SocialDept\AtpParity\Events\RecordUnsynced;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Sync\SyncService;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;

class SyncServiceTest extends TestCase
{
    private SyncService $service;

    private MapperRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new MapperRegistry();
        $this->registry->register(new TestMapper());

        $this->service = new SyncService($this->registry);

        Event::fake();
    }

    public function test_sync_fails_when_no_did_available(): void
    {
        $model = new TestModel(['content' => 'Test']);

        $result = $this->service->sync($model);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('No DID', $result->error);
    }

    public function test_sync_uses_did_from_model_column(): void
    {
        $model = new TestModel([
            'content' => 'Test',
            'did' => 'did:plc:test123',
        ]);

        $this->mockAtpClient('did:plc:test123', 'at://did:plc:test123/app.test.record/abc', 'cid123');

        $result = $this->service->sync($model);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('at://did:plc:test123/app.test.record/abc', $result->uri);
    }

    public function test_sync_as_creates_record_with_specified_did(): void
    {
        $model = new TestModel(['content' => 'Hello world']);

        $this->mockAtpClient('did:plc:specified', 'at://did:plc:specified/app.test.record/xyz', 'newcid');

        $result = $this->service->syncAs('did:plc:specified', $model);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('at://did:plc:specified/app.test.record/xyz', $result->uri);
        $this->assertSame('newcid', $result->cid);
    }

    public function test_sync_as_updates_model_metadata(): void
    {
        $model = TestModel::create(['content' => 'Test']);

        $this->mockAtpClient('did:plc:test', 'at://did:plc:test/app.test.record/rkey', 'cid');

        $this->service->syncAs('did:plc:test', $model);

        $model->refresh();
        $this->assertSame('at://did:plc:test/app.test.record/rkey', $model->atp_uri);
        $this->assertSame('cid', $model->atp_cid);
    }

    public function test_sync_dispatches_record_synced_event(): void
    {
        $model = new TestModel(['content' => 'Test', 'did' => 'did:plc:test']);

        $this->mockAtpClient('did:plc:test', 'at://did/col/rkey', 'cid');

        $this->service->sync($model);

        Event::assertDispatched(RecordSynced::class);
    }

    public function test_sync_redirects_to_resync_when_already_synced(): void
    {
        $model = TestModel::create([
            'content' => 'Existing',
            'atp_uri' => 'at://did:plc:test/app.test.record/existing',
            'atp_cid' => 'oldcid',
        ]);

        $this->mockAtpClientForUpdate('did:plc:test', 'at://did:plc:test/app.test.record/existing', 'newcid');

        $result = $this->service->syncAs('did:plc:test', $model);

        $this->assertTrue($result->isSuccess());
    }

    public function test_resync_fails_when_not_synced(): void
    {
        $model = new TestModel(['content' => 'Not synced']);

        $result = $this->service->resync($model);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('not been synced', $result->error);
    }

    public function test_resync_calls_put_record(): void
    {
        $model = TestModel::create([
            'content' => 'Updated content',
            'atp_uri' => 'at://did:plc:test/app.test.record/rkey123',
            'atp_cid' => 'oldcid',
        ]);

        $this->mockAtpClientForUpdate('did:plc:test', 'at://did:plc:test/app.test.record/rkey123', 'updatedcid');

        $result = $this->service->resync($model);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('updatedcid', $result->cid);
    }

    public function test_unsync_removes_record_and_clears_metadata(): void
    {
        $model = TestModel::create([
            'content' => 'To delete',
            'atp_uri' => 'at://did:plc:test/app.test.record/todelete',
            'atp_cid' => 'cid',
        ]);

        $this->mockAtpClientForDelete('did:plc:test');

        $result = $this->service->unsync($model);

        $this->assertTrue($result);

        $model->refresh();
        $this->assertNull($model->atp_uri);
        $this->assertNull($model->atp_cid);
    }

    public function test_unsync_dispatches_record_unsynced_event(): void
    {
        $model = TestModel::create([
            'content' => 'To delete',
            'atp_uri' => 'at://did:plc:test/app.test.record/xyz',
            'atp_cid' => 'cid',
        ]);

        $this->mockAtpClientForDelete('did:plc:test');

        $this->service->unsync($model);

        Event::assertDispatched(RecordUnsynced::class);
    }

    public function test_unsync_returns_false_when_not_synced(): void
    {
        $model = new TestModel(['content' => 'Not synced']);

        $result = $this->service->unsync($model);

        $this->assertFalse($result);
    }

    public function test_sync_handles_exception_gracefully(): void
    {
        $model = new TestModel(['content' => 'Test', 'did' => 'did:plc:test']);

        $this->mockAtpClientWithException('did:plc:test', 'API error occurred');

        $result = $this->service->sync($model);

        $this->assertTrue($result->isFailed());
        $this->assertSame('API error occurred', $result->error);
    }

    public function test_resync_fails_for_invalid_uri(): void
    {
        $model = new TestModel([
            'content' => 'Test',
            'atp_uri' => 'invalid-uri-format',
        ]);

        $result = $this->service->resync($model);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('Invalid AT Protocol URI', $result->error);
    }

    public function test_model_with_custom_rkey_returns_expected_value(): void
    {
        // Create a model with custom rkey implementation
        $model = new class(['content' => 'Test']) extends TestModel
        {
            public function getDesiredAtpRkey(): ?string
            {
                return 'my-custom-rkey';
            }
        };

        // Verify the model returns the expected rkey
        $this->assertSame('my-custom-rkey', $model->getDesiredAtpRkey());
    }

    public function test_default_model_returns_null_rkey(): void
    {
        $model = new TestModel(['content' => 'Test']);

        // Verify the default implementation returns null
        $this->assertNull($model->getDesiredAtpRkey());
    }

    /**
     * Mock AtpClient for create operations.
     */
    protected function mockAtpClient(string $did, string $returnUri, string $returnCid): void
    {
        $response = new \stdClass();
        $response->uri = $returnUri;
        $response->cid = $returnCid;

        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('createRecord')
            ->andReturn($response);

        // Create client mock with property chain (no typed properties)
        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        // Bind a manager mock to the container
        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with($did)
            ->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    /**
     * Mock AtpClient for update operations.
     */
    protected function mockAtpClientForUpdate(string $did, string $returnUri, string $returnCid): void
    {
        $response = new \stdClass();
        $response->uri = $returnUri;
        $response->cid = $returnCid;

        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('putRecord')
            ->andReturn($response);

        // Create client mock with property chain (no typed properties)
        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        // Bind a manager mock to the container
        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with($did)
            ->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    /**
     * Mock AtpClient for delete operations.
     */
    protected function mockAtpClientForDelete(string $did): void
    {
        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('deleteRecord')
            ->andReturnNull();

        // Create client mock with property chain (no typed properties)
        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        // Bind a manager mock to the container
        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with($did)
            ->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }

    /**
     * Mock AtpClient to throw exception.
     */
    protected function mockAtpClientWithException(string $did, string $message): void
    {
        $manager = Mockery::mock();
        $manager->shouldReceive('as')
            ->with($did)
            ->andThrow(new \Exception($message));

        $this->app->instance('atp-client', $manager);
    }

}
