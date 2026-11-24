<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

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
 * Every record written to a repo carries its top-level `$type`.
 *
 * The data model requires it. The reference PDS fills it in when a write leaves
 * it out, so the omission went unnoticed, while other PDS implementations refuse
 * the write with "Record $type must match its collection" and nothing syncs.
 */
class SendsRecordTypeTest extends TestCase
{
    /** @var list<array{method: string, collection: string, record: array<string, mixed>}> */
    private array $writes = [];

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
        $this->recordWrites('did:plc:test');
    }

    public function test_a_create_sends_the_record_type(): void
    {
        (new SyncService($this->registry))->sync(new TestModel(['content' => 'Hello', 'did' => 'did:plc:test']));

        $this->assertWrites([['createRecord', 'app.test.record']]);
    }

    public function test_a_put_sends_the_record_type(): void
    {
        $model = TestModel::create(['content' => 'Changed', 'atp_uri' => 'at://did:plc:test/app.test.record/abc', 'atp_cid' => 'bafyold']);

        (new SyncService($this->registry))->resync($model, force: true);

        $this->assertWrites([['putRecord', 'app.test.record']]);
    }

    public function test_both_creates_of_a_reference_pair_send_their_record_types(): void
    {
        $service = new ReferenceSyncService($this->registry, new SyncService($this->registry));

        $service->syncWithReference('did:plc:test', ReferenceModel::create(['title' => 'Hello']), new TestReferenceMapper());

        $this->assertWrites([['createRecord', 'app.test.main'], ['createRecord', 'app.test.reference']]);
    }

    public function test_both_puts_of_a_reference_pair_send_their_record_types(): void
    {
        $model = ReferenceModel::create([
            'title' => 'Changed',
            'atp_uri' => 'at://did:plc:test/app.test.main/abc',
            'atp_cid' => 'bafymain',
            'atp_reference_uri' => 'at://did:plc:test/app.test.reference/abc',
            'atp_reference_cid' => 'bafyref',
        ]);
        $service = new ReferenceSyncService($this->registry, new SyncService($this->registry));

        $service->resyncWithReference($model, new TestReferenceMapper(), force: true);

        $this->assertWrites([['putRecord', 'app.test.main'], ['putRecord', 'app.test.reference']]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $expected  method and collection, in order
     */
    private function assertWrites(array $expected): void
    {
        $this->assertSame(
            $expected,
            array_map(fn (array $write) => [$write['method'], $write['collection']], $this->writes),
        );

        foreach ($this->writes as $write) {
            $this->assertSame($write['collection'], $write['record']['$type'] ?? null, "{$write['method']} sent no matching \$type");
        }
    }

    private function recordWrites(string $did): void
    {
        $repoClient = Mockery::mock();

        foreach (['createRecord', 'putRecord'] as $method) {
            $repoClient->shouldReceive($method)->andReturnUsing(function (...$args) use ($method, $did) {
                $collection = $args['collection'];
                $this->writes[] = ['method' => $method, 'collection' => $collection, 'record' => $args['record']];

                $response = new \stdClass();
                $response->uri = "at://{$did}/{$collection}/".($args['rkey'] ?? 'abc');
                $response->cid = "bafy{$method}".count($this->writes);

                return $response;
            });
        }

        $atprotoClient = Mockery::mock();
        $atprotoClient->repo = $repoClient;

        $atpClient = Mockery::mock();
        $atpClient->atproto = $atprotoClient;

        $manager = Mockery::mock();
        $manager->shouldReceive('as')->with($did)->andReturn($atpClient);

        $this->app->instance('atp-client', $manager);
    }
}
