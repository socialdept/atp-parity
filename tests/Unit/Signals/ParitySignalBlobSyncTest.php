<?php

namespace SocialDept\AtpParity\Tests\Unit\Signals;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Signals\ParitySignal;
use SocialDept\AtpParity\Tests\Fixtures\MediaMapper;
use SocialDept\AtpParity\Tests\Fixtures\MediaModel;
use SocialDept\AtpParity\Tests\TestCase;

class ParitySignalBlobSyncTest extends TestCase
{
    protected ParitySignal $signal;

    protected MapperRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMediaModelsTable();

        $this->registry = app(MapperRegistry::class);
        $this->registry->register(new MediaMapper());

        $this->signal = app(ParitySignal::class);
    }

    protected function createMediaModelsTable(): void
    {
        \Illuminate\Support\Facades\Schema::create('media_models', function ($table) {
            $table->id();
            $table->string('content')->nullable();
            $table->string('atp_uri')->nullable()->unique();
            $table->string('atp_cid')->nullable();
            $table->json('atp_blobs')->nullable();
            $table->timestamp('atp_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_sync_blobs_if_changed_passes_did_to_model(): void
    {
        $model = new MediaModel([
            'atp_uri' => 'at://did:plc:test/app.test/123',
            'atp_blobs' => [
                'icon' => ['cid' => 'bafynew123', 'mimeType' => 'image/png', 'size' => 1024],
            ],
        ]);

        $mapper = new MediaMapper();
        $oldBlobs = null; // No previous blobs, so this is a change
        $did = 'did:plc:eventdid';

        // Use reflection to call protected method
        $reflection = new \ReflectionClass($this->signal);
        $method = $reflection->getMethod('syncBlobsIfChanged');
        $method->setAccessible(true);

        $method->invoke($this->signal, $model, $mapper, $oldBlobs, $did);

        $this->assertCount(1, $model->syncCalls);
        $this->assertSame('did:plc:eventdid', $model->syncCalls[0]['did']);
    }

    public function test_sync_blobs_if_changed_skips_when_blobs_unchanged(): void
    {
        $blobs = [
            'icon' => ['cid' => 'bafysame123', 'mimeType' => 'image/png', 'size' => 1024],
        ];

        $model = new MediaModel([
            'atp_uri' => 'at://did:plc:test/app.test/123',
            'atp_blobs' => $blobs,
        ]);

        $mapper = new MediaMapper();
        $oldBlobs = $blobs; // Same as current, no change
        $did = 'did:plc:eventdid';

        $reflection = new \ReflectionClass($this->signal);
        $method = $reflection->getMethod('syncBlobsIfChanged');
        $method->setAccessible(true);

        $method->invoke($this->signal, $model, $mapper, $oldBlobs, $did);

        // Should not have called sync since blobs are unchanged
        $this->assertCount(0, $model->syncCalls);
    }

    public function test_sync_blobs_if_changed_triggers_when_cid_differs(): void
    {
        $model = new MediaModel([
            'atp_uri' => 'at://did:plc:test/app.test/123',
            'atp_blobs' => [
                'icon' => ['cid' => 'bafynewcid', 'mimeType' => 'image/png', 'size' => 1024],
            ],
        ]);

        $mapper = new MediaMapper();
        $oldBlobs = [
            'icon' => ['cid' => 'bafyoldcid', 'mimeType' => 'image/png', 'size' => 1024],
        ];
        $did = 'did:plc:eventdid';

        $reflection = new \ReflectionClass($this->signal);
        $method = $reflection->getMethod('syncBlobsIfChanged');
        $method->setAccessible(true);

        $method->invoke($this->signal, $model, $mapper, $oldBlobs, $did);

        // Should have called sync since CID changed
        $this->assertCount(1, $model->syncCalls);
        $this->assertSame('did:plc:eventdid', $model->syncCalls[0]['did']);
    }

    public function test_sync_blobs_if_changed_skips_when_mapper_has_no_blob_fields(): void
    {
        $model = new MediaModel([
            'atp_uri' => 'at://did:plc:test/app.test/123',
            'atp_blobs' => [
                'icon' => ['cid' => 'bafynew123', 'mimeType' => 'image/png', 'size' => 1024],
            ],
        ]);

        // Create a mapper mock that has no blob fields
        $mapper = $this->createMock(RecordMapper::class);
        $mapper->method('hasBlobFields')->willReturn(false);

        $reflection = new \ReflectionClass($this->signal);
        $method = $reflection->getMethod('syncBlobsIfChanged');
        $method->setAccessible(true);

        $method->invoke($this->signal, $model, $mapper, null, 'did:plc:test');

        // Should not have called sync since mapper has no blob fields
        $this->assertCount(0, $model->syncCalls);
    }

    public function test_sync_blobs_if_changed_skips_when_model_lacks_method(): void
    {
        // Use a model without syncAtpBlobsToMedia method
        $model = new class extends Model
        {
            protected $table = 'test_models';

            protected $guarded = [];

            protected $casts = ['atp_blobs' => 'array'];
        };
        $model->atp_blobs = ['icon' => ['cid' => 'bafy123', 'mimeType' => 'image/png', 'size' => 100]];

        $mapper = new MediaMapper();

        $reflection = new \ReflectionClass($this->signal);
        $method = $reflection->getMethod('syncBlobsIfChanged');
        $method->setAccessible(true);

        // Should not throw, just skip
        $method->invoke($this->signal, $model, $mapper, null, 'did:plc:test');

        // No exception means it handled the missing method gracefully
        $this->assertTrue(true);
    }

    public function test_blobs_unchanged_returns_true_for_identical_cids(): void
    {
        $blobs = [
            'icon' => ['cid' => 'bafyicon', 'mimeType' => 'image/png', 'size' => 1024],
            'images' => [
                ['cid' => 'bafyimg1', 'mimeType' => 'image/jpeg', 'size' => 2048],
                ['cid' => 'bafyimg2', 'mimeType' => 'image/jpeg', 'size' => 3072],
            ],
        ];

        $reflection = new \ReflectionClass($this->signal);
        $method = $reflection->getMethod('blobsUnchanged');
        $method->setAccessible(true);

        $result = $method->invoke($this->signal, $blobs, $blobs);

        $this->assertTrue($result);
    }

    public function test_blobs_unchanged_returns_false_for_different_cids(): void
    {
        $oldBlobs = [
            'icon' => ['cid' => 'bafyold', 'mimeType' => 'image/png', 'size' => 1024],
        ];
        $newBlobs = [
            'icon' => ['cid' => 'bafynew', 'mimeType' => 'image/png', 'size' => 1024],
        ];

        $reflection = new \ReflectionClass($this->signal);
        $method = $reflection->getMethod('blobsUnchanged');
        $method->setAccessible(true);

        $result = $method->invoke($this->signal, $oldBlobs, $newBlobs);

        $this->assertFalse($result);
    }

    public function test_blobs_unchanged_handles_null_values(): void
    {
        $reflection = new \ReflectionClass($this->signal);
        $method = $reflection->getMethod('blobsUnchanged');
        $method->setAccessible(true);

        // Both null = unchanged
        $this->assertTrue($method->invoke($this->signal, null, null));

        // Null to something = changed
        $this->assertFalse($method->invoke($this->signal, null, ['icon' => ['cid' => 'bafy']]));

        // Something to null = changed
        $this->assertFalse($method->invoke($this->signal, ['icon' => ['cid' => 'bafy']], null));
    }

    public function test_extract_blob_cids_extracts_single_blobs(): void
    {
        $blobs = [
            'icon' => ['cid' => 'bafyicon123', 'mimeType' => 'image/png', 'size' => 1024],
        ];

        $reflection = new \ReflectionClass($this->signal);
        $method = $reflection->getMethod('extractBlobCids');
        $method->setAccessible(true);

        $cids = $method->invoke($this->signal, $blobs);

        $this->assertSame(['icon' => 'bafyicon123'], $cids);
    }

    public function test_extract_blob_cids_extracts_array_blobs(): void
    {
        $blobs = [
            'images' => [
                ['cid' => 'bafyimg1', 'mimeType' => 'image/png', 'size' => 512],
                ['cid' => 'bafyimg2', 'mimeType' => 'image/jpeg', 'size' => 768],
            ],
        ];

        $reflection = new \ReflectionClass($this->signal);
        $method = $reflection->getMethod('extractBlobCids');
        $method->setAccessible(true);

        $cids = $method->invoke($this->signal, $blobs);

        $this->assertSame(['images' => ['bafyimg1', 'bafyimg2']], $cids);
    }

    public function test_extract_blob_cids_handles_mixed_fields(): void
    {
        $blobs = [
            'avatar' => ['cid' => 'bafyavatar', 'mimeType' => 'image/png', 'size' => 1024],
            'gallery' => [
                ['cid' => 'bafygal1', 'mimeType' => 'image/jpeg', 'size' => 2048],
                ['cid' => 'bafygal2', 'mimeType' => 'image/jpeg', 'size' => 3072],
            ],
        ];

        $reflection = new \ReflectionClass($this->signal);
        $method = $reflection->getMethod('extractBlobCids');
        $method->setAccessible(true);

        $cids = $method->invoke($this->signal, $blobs);

        $this->assertSame([
            'avatar' => 'bafyavatar',
            'gallery' => ['bafygal1', 'bafygal2'],
        ], $cids);
    }
}
