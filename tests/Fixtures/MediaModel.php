<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\HasAtpRecord;
use SocialDept\AtpParity\Concerns\InteractsWithMediaLibrary;

/**
 * Test model for MediaLibrary integration testing.
 *
 * Note: This model implements a mock HasMedia interface for testing
 * without requiring the actual Spatie MediaLibrary package.
 */
class MediaModel extends Model
{
    use HasAtpRecord;
    use InteractsWithMediaLibrary {
        InteractsWithMediaLibrary::mediaLibraryAvailable as baseMediaLibraryAvailable;
    }

    protected $table = 'media_models';

    protected $guarded = [];

    protected $casts = [
        'atp_blobs' => 'array',
        'atp_synced_at' => 'datetime',
    ];

    /**
     * Track calls to syncAtpBlobsToMedia for testing.
     */
    public array $syncCalls = [];

    /**
     * Whether to simulate MediaLibrary being available.
     */
    public bool $simulateMediaLibrary = true;

    /**
     * Map AT Protocol blob fields to MediaLibrary collections.
     */
    public function atpBlobToMediaCollections(): array
    {
        return [
            'icon' => 'icons',
            'images' => 'gallery',
        ];
    }

    /**
     * Override to track calls and test DID parameter.
     */
    public function syncAtpBlobsToMedia(?string $did = null): void
    {
        $this->syncCalls[] = [
            'did' => $did,
            'atp_blobs' => $this->getAttribute('atp_blobs'),
        ];

        // Don't call parent - we're just tracking calls for testing
    }

    /**
     * Override to control MediaLibrary availability in tests.
     */
    protected function mediaLibraryAvailable(): bool
    {
        return $this->simulateMediaLibrary;
    }
}
