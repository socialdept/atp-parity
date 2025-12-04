<?php

namespace SocialDept\AtpParity\Concerns;

use Illuminate\Support\Facades\File;
use SocialDept\AtpParity\Blob\BlobManager;
use SocialDept\AtpParity\Enums\BlobStorageDriver;
use SocialDept\AtpSchema\Data\BlobReference;

/**
 * Optional trait for Spatie MediaLibrary integration.
 *
 * This trait extends HasAtpBlobs with MediaLibrary functionality.
 * Requires spatie/laravel-medialibrary to be installed.
 *
 * In MediaLibrary storage mode, this trait handles all blob storage
 * directly without the parity_blob_mappings table.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 * @mixin \Spatie\MediaLibrary\InteractsWithMedia
 */
trait InteractsWithMediaLibrary
{
    use HasAtpBlobs;

    /**
     * Map AT Protocol blob fields to MediaLibrary collections.
     * Override in your model.
     *
     * @return array<string, string> Blob field name => MediaLibrary collection name
     */
    public function atpBlobToMediaCollections(): array
    {
        return [];
    }

    /**
     * Sync AT Protocol blobs to MediaLibrary.
     * Downloads blobs if needed and adds to collections.
     */
    public function syncAtpBlobsToMedia(): void
    {
        if (! $this->mediaLibraryAvailable()) {
            return;
        }

        $manager = app(BlobManager::class);
        $did = $this->getAtpDid();

        if (! $did) {
            return;
        }

        $blobs = $this->getAttribute('atp_blobs') ?? [];
        $collectionMap = $this->atpBlobToMediaCollections();

        foreach ($collectionMap as $field => $collection) {
            if (! isset($blobs[$field])) {
                continue;
            }

            $data = $blobs[$field];

            // Single blob
            if (isset($data['cid'])) {
                $this->addAtpBlobToCollection($data, $collection, $did, $manager);

                continue;
            }

            // Array of blobs
            foreach ($data as $item) {
                if (isset($item['cid'])) {
                    $this->addAtpBlobToCollection($item, $collection, $did, $manager);
                }
            }
        }
    }

    /**
     * Upload MediaLibrary items to AT Protocol.
     *
     * @return array<BlobReference>
     */
    public function uploadMediaToAtp(string $collection, string $did): array
    {
        if (! $this->mediaLibraryAvailable()) {
            return [];
        }

        $manager = app(BlobManager::class);
        $blobRefs = [];

        foreach ($this->getMedia($collection) as $media) {
            $blobRef = $manager->upload($did, $media->getPath(), $media->mime_type);
            $blobRefs[] = $blobRef;

            // Store CID in media custom properties
            $media->setCustomProperty('atp_cid', $blobRef->getCid());
            $media->save();
        }

        return $blobRefs;
    }

    /**
     * Get MediaLibrary media item by AT Protocol CID.
     *
     * @return \Spatie\MediaLibrary\MediaCollections\Models\Media|null
     */
    public function getMediaByCid(string $cid, ?string $collection = null)
    {
        if (! $this->mediaLibraryAvailable()) {
            return null;
        }

        $media = $collection
            ? $this->getMedia($collection)
            : $this->media;

        return $media->first(
            fn ($m) => $m->getCustomProperty('atp_cid') === $cid
        );
    }

    /**
     * Check if a blob is already in a MediaLibrary collection.
     */
    public function hasBlobInCollection(string $cid, string $collection): bool
    {
        return $this->getMediaByCid($cid, $collection) !== null;
    }

    /**
     * Download an AT Protocol blob directly to a MediaLibrary collection.
     *
     * This method downloads blob content and adds it to MediaLibrary without
     * creating a BlobMapping record. Use this in MediaLibrary storage mode.
     *
     * @return \Spatie\MediaLibrary\MediaCollections\Models\Media|null
     */
    public function downloadAtpBlobToMedia(
        BlobReference|array $blob,
        string $collection,
        ?string $did = null
    ) {
        if (! $this->mediaLibraryAvailable()) {
            return null;
        }

        $did = $did ?? $this->getAtpDid();
        if (! $did) {
            return null;
        }

        // Normalize to BlobReference
        if (is_array($blob)) {
            $blob = new BlobReference(
                $blob['cid'],
                $blob['mimeType'] ?? 'application/octet-stream',
                $blob['size'] ?? 0
            );
        }

        // Check if already in collection by CID
        if ($this->hasBlobInCollection($blob->getCid(), $collection)) {
            return $this->getMediaByCid($blob->getCid(), $collection);
        }

        // Download content directly (no BlobMapping created)
        $manager = app(BlobManager::class);
        $content = $manager->downloadContent($blob, $did);

        // Write to temp file
        $extension = $this->mimeToExtension($blob->getMimeType());
        $tempPath = sys_get_temp_dir().'/'.$blob->getCid().'.'.$extension;
        File::put($tempPath, $content);

        try {
            // Add to MediaLibrary
            $media = $this->addMedia($tempPath)
                ->withCustomProperties([
                    'atp_cid' => $blob->getCid(),
                    'atp_did' => $did,
                ])
                ->toMediaCollection($collection);

            return $media;
        } finally {
            // Clean up temp file
            @unlink($tempPath);
        }
    }

    /**
     * Add an AT Protocol blob to a MediaLibrary collection.
     *
     * In filesystem mode, downloads via BlobManager then copies to MediaLibrary.
     * In MediaLibrary mode, downloads directly to MediaLibrary.
     */
    protected function addAtpBlobToCollection(
        array $blobData,
        string $collection,
        string $did,
        BlobManager $manager
    ): void {
        // Check if already in collection by CID
        if ($this->hasBlobInCollection($blobData['cid'], $collection)) {
            return;
        }

        $blob = new BlobReference(
            $blobData['cid'],
            $blobData['mimeType'] ?? 'application/octet-stream',
            $blobData['size'] ?? 0
        );

        // In MediaLibrary mode, download directly to collection
        if ($manager->usesMediaLibrary()) {
            $this->downloadAtpBlobToMedia($blob, $collection, $did);

            return;
        }

        // Filesystem mode: download via BlobManager, then copy to MediaLibrary
        $mapping = $manager->download($blob, $did);

        $fullPath = $mapping->getFullPath();
        if (! $fullPath) {
            return;
        }

        // Add to MediaLibrary
        $this->addMedia($fullPath)
            ->preservingOriginal()
            ->withCustomProperties(['atp_cid' => $blobData['cid']])
            ->toMediaCollection($collection);
    }

    /**
     * Get the MediaLibrary URL for a blob by CID.
     *
     * @return string|null The URL or null if not found in MediaLibrary
     */
    public function getMediaUrlByCid(string $cid, ?string $collection = null): ?string
    {
        $media = $this->getMediaByCid($cid, $collection);

        return $media?->getUrl();
    }

    /**
     * Check if using MediaLibrary storage driver.
     */
    protected function usesMediaLibraryStorage(): bool
    {
        return config('parity.blobs.storage_driver', BlobStorageDriver::Filesystem) === BlobStorageDriver::MediaLibrary;
    }

    /**
     * Check if MediaLibrary is available.
     */
    protected function mediaLibraryAvailable(): bool
    {
        return interface_exists(\Spatie\MediaLibrary\HasMedia::class)
            && $this instanceof \Spatie\MediaLibrary\HasMedia;
    }

    /**
     * Convert MIME type to file extension.
     */
    protected function mimeToExtension(string $mimeType): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'application/pdf' => 'pdf',
        ];

        return $map[$mimeType] ?? 'bin';
    }
}
