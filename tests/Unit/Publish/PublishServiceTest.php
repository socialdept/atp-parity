<?php

namespace SocialDept\AtpParity\Tests\Unit\Publish;

use Illuminate\Support\Facades\Event;
use Mockery;
use SocialDept\AtpParity\Events\RecordPublished;
use SocialDept\AtpParity\Events\RecordUnpublished;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Publish\PublishService;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;

class PublishServiceTest extends TestCase
{
    private PublishService $service;

    private MapperRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new MapperRegistry();
        $this->registry->register(new TestMapper());

        $this->service = new PublishService($this->registry);

        Event::fake();
    }

    public function test_publish_fails_when_no_did_available(): void
    {
        $model = new TestModel(['content' => 'Test']);

        $result = $this->service->publish($model);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('No DID', $result->error);
    }

    public function test_publish_uses_did_from_model_column(): void
    {
        $model = new TestModel([
            'content' => 'Test',
            'did' => 'did:plc:test123',
        ]);

        $this->mockAtpClient('did:plc:test123', 'at://did:plc:test123/app.test.record/abc', 'cid123');

        $result = $this->service->publish($model);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('at://did:plc:test123/app.test.record/abc', $result->uri);
    }

    public function test_publish_as_creates_record_with_specified_did(): void
    {
        $model = new TestModel(['content' => 'Hello world']);

        $this->mockAtpClient('did:plc:specified', 'at://did:plc:specified/app.test.record/xyz', 'newcid');

        $result = $this->service->publishAs('did:plc:specified', $model);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('at://did:plc:specified/app.test.record/xyz', $result->uri);
        $this->assertSame('newcid', $result->cid);
    }

    public function test_publish_as_updates_model_metadata(): void
    {
        $model = TestModel::create(['content' => 'Test']);

        $this->mockAtpClient('did:plc:test', 'at://did:plc:test/app.test.record/rkey', 'cid');

        $this->service->publishAs('did:plc:test', $model);

        $model->refresh();
        $this->assertSame('at://did:plc:test/app.test.record/rkey', $model->atp_uri);
        $this->assertSame('cid', $model->atp_cid);
    }

    public function test_publish_dispatches_record_published_event(): void
    {
        $model = new TestModel(['content' => 'Test', 'did' => 'did:plc:test']);

        $this->mockAtpClient('did:plc:test', 'at://did/col/rkey', 'cid');

        $this->service->publish($model);

        Event::assertDispatched(RecordPublished::class);
    }

    public function test_publish_redirects_to_update_when_already_published(): void
    {
        $model = TestModel::create([
            'content' => 'Existing',
            'atp_uri' => 'at://did:plc:test/app.test.record/existing',
            'atp_cid' => 'oldcid',
        ]);

        $this->mockAtpClientForUpdate('did:plc:test', 'at://did:plc:test/app.test.record/existing', 'newcid');

        $result = $this->service->publishAs('did:plc:test', $model);

        $this->assertTrue($result->isSuccess());
    }

    public function test_update_fails_when_not_published(): void
    {
        $model = new TestModel(['content' => 'Not published']);

        $result = $this->service->update($model);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('not been published', $result->error);
    }

    public function test_update_calls_put_record(): void
    {
        $model = TestModel::create([
            'content' => 'Updated content',
            'atp_uri' => 'at://did:plc:test/app.test.record/rkey123',
            'atp_cid' => 'oldcid',
        ]);

        $this->mockAtpClientForUpdate('did:plc:test', 'at://did:plc:test/app.test.record/rkey123', 'updatedcid');

        $result = $this->service->update($model);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('updatedcid', $result->cid);
    }

    public function test_delete_removes_record_and_clears_metadata(): void
    {
        $model = TestModel::create([
            'content' => 'To delete',
            'atp_uri' => 'at://did:plc:test/app.test.record/todelete',
            'atp_cid' => 'cid',
        ]);

        $this->mockAtpClientForDelete('did:plc:test');

        $result = $this->service->delete($model);

        $this->assertTrue($result);

        $model->refresh();
        $this->assertNull($model->atp_uri);
        $this->assertNull($model->atp_cid);
    }

    public function test_delete_dispatches_record_unpublished_event(): void
    {
        $model = TestModel::create([
            'content' => 'To delete',
            'atp_uri' => 'at://did:plc:test/app.test.record/xyz',
            'atp_cid' => 'cid',
        ]);

        $this->mockAtpClientForDelete('did:plc:test');

        $this->service->delete($model);

        Event::assertDispatched(RecordUnpublished::class);
    }

    public function test_delete_returns_false_when_not_published(): void
    {
        $model = new TestModel(['content' => 'Not published']);

        $result = $this->service->delete($model);

        $this->assertFalse($result);
    }

    public function test_publish_handles_exception_gracefully(): void
    {
        $model = new TestModel(['content' => 'Test', 'did' => 'did:plc:test']);

        $this->mockAtpClientWithException('did:plc:test', 'API error occurred');

        $result = $this->service->publish($model);

        $this->assertTrue($result->isFailed());
        $this->assertSame('API error occurred', $result->error);
    }

    public function test_update_fails_for_invalid_uri(): void
    {
        $model = new TestModel([
            'content' => 'Test',
            'atp_uri' => 'invalid-uri-format',
        ]);

        $result = $this->service->update($model);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('Invalid AT Protocol URI', $result->error);
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
