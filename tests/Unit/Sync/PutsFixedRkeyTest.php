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
 * A first write with a fixed rkey uses putRecord.
 *
 * When a PDS saves a record but its reply never arrives, the model keeps no URI
 * and the next sync writes again. createRecord then fails on the record that
 * already exists, on every retry, while putRecord overwrites it and the model
 * recovers.
 */
class PutsFixedRkeyTest extends TestCase
{
    /** @var list<array{method: string, collection: string, rkey: ?string}> */
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

    public function test_a_first_write_with_a_fixed_rkey_is_put(): void
    {
        $model = new class (['content' => 'Hello', 'did' => 'did:plc:test']) extends TestModel {
            public function getDesiredAtpRkey(): ?string
            {
                return 'fixed';
            }
        };

        $result = (new SyncService($this->registry))->syncAsWithMapper('did:plc:test', $model, new TestMapper());

        $this->assertTrue($result->isSuccess(), (string) $result->error);
        $this->assertSame([['putRecord', 'app.test.record', 'fixed']], $this->writes);
        $this->assertSame('at://did:plc:test/app.test.record/fixed', $model->atp_uri);
    }

    public function test_a_first_write_without_an_rkey_is_still_created(): void
    {
        (new SyncService($this->registry))->sync(new TestModel(['content' => 'Hello', 'did' => 'did:plc:test']));

        $this->assertSame([['createRecord', 'app.test.record', null]], $this->writes);
    }

    public function test_a_reference_pair_with_fixed_rkeys_is_put(): void
    {
        $model = new class (['title' => 'Hello']) extends ReferenceModel {
            public function getDesiredAtpRkey(): ?string
            {
                return 'main-rkey';
            }

            public function getDesiredAtpReferenceRkey(): ?string
            {
                return 'reference-rkey';
            }
        };
        $model->save();

        (new ReferenceSyncService($this->registry, new SyncService($this->registry)))
            ->syncWithReference('did:plc:test', $model, new TestReferenceMapper());

        $this->assertSame([
            ['putRecord', 'app.test.main', 'main-rkey'],
            ['putRecord', 'app.test.reference', 'reference-rkey'],
        ], $this->writes);
    }

    private function recordWrites(string $did): void
    {
        $repoClient = Mockery::mock();

        foreach (['createRecord', 'putRecord'] as $method) {
            $repoClient->shouldReceive($method)->andReturnUsing(function (...$args) use ($method, $did) {
                $this->writes[] = [$method, $args['collection'], $args['rkey'] ?? null];

                $response = new \stdClass();
                $response->uri = "at://{$did}/{$args['collection']}/".($args['rkey'] ?? 'generated');
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
