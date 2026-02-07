<?php

namespace SocialDept\AtpParity\Tests\Unit\Import;

use Illuminate\Support\Facades\Event;
use Mockery;
use SocialDept\AtpParity\Events\ImportCompleted;
use SocialDept\AtpParity\Events\ImportFailed;
use SocialDept\AtpParity\Events\ImportProgress;
use SocialDept\AtpParity\Events\ImportStarted;
use SocialDept\AtpParity\Import\ImportService;
use SocialDept\AtpParity\Import\ImportState;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Tests\Fixtures\TestMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;
use SocialDept\AtpSupport\Facades\Resolver;

class ImportServiceTest extends TestCase
{
    private ImportService $service;

    private MapperRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new MapperRegistry();
        $this->registry->register(new TestMapper());

        $this->service = new ImportService($this->registry);

        Event::fake();
    }

    public function test_import_user_collection_returns_failed_when_no_mapper(): void
    {
        $this->app->forgetInstance(MapperRegistry::class);
        $emptyRegistry = new MapperRegistry();
        $service = new ImportService($emptyRegistry);

        $result = $service->importUserCollection('did:plc:test', 'unknown.collection');

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('No mapper registered', $result->error);
    }

    public function test_import_user_collection_fails_when_pds_not_resolved(): void
    {
        Resolver::shouldReceive('resolvePds')
            ->with('did:plc:test')
            ->andReturnNull();

        $result = $this->service->importUserCollection('did:plc:test', 'app.test.record');

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('Could not resolve PDS', $result->error);
        Event::assertDispatched(ImportFailed::class);
    }

    /**
     * @group integration
     */
    public function test_import_user_collection_imports_records(): void
    {
        $this->markTestSkipped('Requires integration test with real or mock ATP client - AtpClient has typed properties that prevent mocking');
    }

    /**
     * @group integration
     */
    public function test_import_dispatches_events(): void
    {
        $this->markTestSkipped('Requires integration test with real or mock ATP client - AtpClient has typed properties that prevent mocking');
    }

    /**
     * @group integration
     */
    public function test_import_calls_progress_callback(): void
    {
        $this->markTestSkipped('Requires integration test with real or mock ATP client - AtpClient has typed properties that prevent mocking');
    }

    /**
     * @group integration
     */
    public function test_import_user_imports_multiple_collections(): void
    {
        $this->markTestSkipped('Requires integration test with real or mock ATP client - AtpClient has typed properties that prevent mocking');
    }

    public function test_get_status_returns_import_state(): void
    {
        ImportState::create([
            'did' => 'did:plc:test',
            'collection' => 'app.test.record',
            'status' => 'completed',
            'records_synced' => 50,
        ]);

        $state = $this->service->getStatus('did:plc:test', 'app.test.record');

        $this->assertNotNull($state);
        $this->assertSame('completed', $state->status);
        $this->assertSame(50, $state->records_synced);
    }

    public function test_get_status_returns_null_when_not_found(): void
    {
        $state = $this->service->getStatus('did:plc:unknown', 'unknown');

        $this->assertNull($state);
    }

    public function test_is_imported_returns_true_when_completed(): void
    {
        ImportState::create([
            'did' => 'did:plc:test',
            'collection' => 'app.test.record',
            'status' => 'completed',
        ]);

        $this->assertTrue($this->service->isImported('did:plc:test', 'app.test.record'));
    }

    public function test_is_imported_returns_false_when_not_started(): void
    {
        $this->assertFalse($this->service->isImported('did:plc:test', 'app.test.record'));
    }

    public function test_reset_deletes_import_state(): void
    {
        ImportState::create([
            'did' => 'did:plc:test',
            'collection' => 'app.test.record',
            'status' => 'completed',
        ]);

        $this->service->reset('did:plc:test', 'app.test.record');

        $this->assertNull($this->service->getStatus('did:plc:test', 'app.test.record'));
    }

    public function test_import_skips_already_completed(): void
    {
        ImportState::create([
            'did' => 'did:plc:test',
            'collection' => 'app.test.record',
            'status' => 'completed',
            'records_synced' => 100,
        ]);

        // No mocking needed - should return cached result
        $result = $this->service->importUserCollection('did:plc:test', 'app.test.record');

        $this->assertTrue($result->completed);
        $this->assertSame(100, $result->recordsSynced);
    }

    /**
     * @group integration
     */
    public function test_import_handles_record_failures_gracefully(): void
    {
        $this->markTestSkipped('Requires integration test with real or mock ATP client - AtpClient has typed properties that prevent mocking');
    }
}
