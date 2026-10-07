<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use SocialDept\AtpParity\Blob\BlobManager;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Support\BlobCid;
use SocialDept\AtpParity\Support\RecordCid;
use SocialDept\AtpParity\Sync\SyncService;
use SocialDept\AtpParity\Tests\Fixtures\OverflowMapper;
use SocialDept\AtpParity\Tests\Fixtures\RecordingBlobManager;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;
use SocialDept\AtpSchema\Data\BlobReference;

/**
 * A resync that writes nothing uploads nothing.
 *
 * Overflow ran while the record was built, and the record was built before the
 * unchanged-write guard looked at it, so every resync of a long record uploaded
 * its content as a blob even when the guard then skipped the write. A blob's CID
 * is the hash of its bytes, so the guard can decide on a locally addressed draft
 * and leave the upload to a write that actually happens.
 */
class OverflowUploadsOnlyOnWriteTest extends TestCase
{
    private const URI = 'at://did:plc:tester/app.test.overflowrecord/abc';

    private RecordingBlobManager $blobs;

    private OverflowMapper $mapper;

    private int $writes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Answers with the CID a PDS gives the bytes, as a real upload does.
        $this->blobs = new class () extends RecordingBlobManager {
            public function uploadFromContent(string $did, string $content, string $mimeType): BlobReference
            {
                $this->uploads[] = ['did' => $did, 'content' => $content, 'mimeType' => $mimeType];

                return new BlobReference(ref: BlobCid::for($content), mimeType: $mimeType, size: strlen($content));
            }
        };
        $this->app->instance(BlobManager::class, $this->blobs);

        $this->mapper = new OverflowMapper();
        $this->writes = 0;

        Event::fake();
    }

    private function model(string $paragraph): TestModel
    {
        DB::table('test_models')->insert(['id' => 1, 'did' => 'did:plc:tester', 'atp_uri' => self::URI]);

        $items = [];

        for ($i = 0; $i < 20; $i++) {
            $items[] = ['type' => 'text', 'text' => "{$paragraph} {$i} of a document that keeps going."];
        }

        $model = new TestModel(['did' => 'did:plc:tester', 'title' => 'A document', 'body' => $items, 'atp_uri' => self::URI]);
        $model->id = 1;
        $model->exists = true;
        $model->syncOriginal();

        return $model;
    }

    /**
     * The CID the last real write stored: the record carrying the uploaded blob.
     */
    private function storeWrittenCid(TestModel $model): void
    {
        $model->atp_cid = RecordCid::for($this->mapper->toRecord($model)->toRecord());
        $model->syncOriginal();

        $this->blobs->uploads = [];
    }

    private function mockPds(): void
    {
        $response = new \stdClass();
        $response->uri = self::URI;
        $response->cid = 'bafyreiwritten';

        $repoClient = Mockery::mock();
        $repoClient->shouldReceive('putRecord')->andReturnUsing(function (...$args) use ($response) {
            $this->writes++;

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

    private function resync(TestModel $model): \SocialDept\AtpParity\Sync\SyncResult
    {
        $registry = new MapperRegistry();
        $registry->register($this->mapper);

        return (new SyncService($registry))->resyncWithMapper($model, $this->mapper);
    }

    public function test_an_unchanged_overflowing_record_uploads_nothing(): void
    {
        $model = $this->model('Paragraph');
        $this->storeWrittenCid($model);
        $this->mockPds();

        $result = $this->resync($model);

        $this->assertTrue($result->unchanged);
        $this->assertSame([], $this->blobs->uploads);
        $this->assertSame(0, $this->writes);
    }

    public function test_a_changed_overflowing_record_still_uploads_and_writes(): void
    {
        $model = $this->model('Paragraph');
        $this->storeWrittenCid($model);
        $this->mockPds();

        $model->body = array_map(
            fn (array $item) => ['type' => 'text', 'text' => 'Rewritten: '.$item['text']],
            $model->body,
        );

        // The table has no content columns, so stand in for an edit already saved.
        $model->syncOriginal();

        $result = $this->resync($model);

        $this->assertNull($result->error);
        $this->assertFalse($result->unchanged);
        $this->assertCount(1, $this->blobs->uploads);
        $this->assertSame(1, $this->writes);
    }
}
