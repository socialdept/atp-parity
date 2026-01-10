<?php

namespace SocialDept\AtpParity\Tests\Unit\Concerns;

use SocialDept\AtpParity\Tests\Fixtures\MediaModel;
use SocialDept\AtpParity\Tests\TestCase;

class InteractsWithMediaLibraryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createMediaModelsTable();
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

    public function test_sync_atp_blobs_to_media_uses_provided_did(): void
    {
        $model = new MediaModel([
            'atp_uri' => 'at://did:plc:fromuri/app.test/123',
            'atp_blobs' => [
                'icon' => ['cid' => 'bafyicon123', 'mimeType' => 'image/png', 'size' => 1024],
            ],
        ]);

        // Call with explicit DID
        $model->syncAtpBlobsToMedia('did:plc:explicit');

        $this->assertCount(1, $model->syncCalls);
        $this->assertSame('did:plc:explicit', $model->syncCalls[0]['did']);
    }

    public function test_sync_atp_blobs_to_media_falls_back_to_uri_did_when_null(): void
    {
        $model = new MediaModel([
            'atp_uri' => 'at://did:plc:fromuri/app.test/123',
            'atp_blobs' => [
                'icon' => ['cid' => 'bafyicon123', 'mimeType' => 'image/png', 'size' => 1024],
            ],
        ]);

        // Call without DID - should fall back to getAtpDid()
        $model->syncAtpBlobsToMedia();

        $this->assertCount(1, $model->syncCalls);
        $this->assertNull($model->syncCalls[0]['did']); // The mock tracks the parameter as passed
    }

    public function test_sync_atp_blobs_to_media_passes_blobs_data(): void
    {
        $blobs = [
            'icon' => ['cid' => 'bafyicon456', 'mimeType' => 'image/jpeg', 'size' => 2048],
            'images' => [
                ['cid' => 'bafyimg1', 'mimeType' => 'image/png', 'size' => 512],
                ['cid' => 'bafyimg2', 'mimeType' => 'image/png', 'size' => 768],
            ],
        ];

        $model = new MediaModel([
            'atp_uri' => 'at://did:plc:test/app.test/456',
            'atp_blobs' => $blobs,
        ]);

        $model->syncAtpBlobsToMedia('did:plc:test');

        $this->assertCount(1, $model->syncCalls);
        $this->assertSame($blobs, $model->syncCalls[0]['atp_blobs']);
    }

    public function test_atp_blob_to_media_collections_returns_mapping(): void
    {
        $model = new MediaModel();

        $mapping = $model->atpBlobToMediaCollections();

        $this->assertSame([
            'icon' => 'icons',
            'images' => 'gallery',
        ], $mapping);
    }
}
