<?php

namespace SocialDept\AtpParity\Blob;

use Illuminate\Http\UploadedFile;
use SocialDept\AtpParity\Contracts\BlobStorage;
use SocialDept\AtpParity\Enums\BlobStorageDriver;
use SocialDept\AtpParity\Enums\BlobUrlStrategy;
use SocialDept\AtpSupport\Facades\Resolver;
use SocialDept\AtpSchema\Data\BlobReference;
use SplFileInfo;

/**
 * Central manager for blob operations.
 *
 * In Filesystem mode, this manager handles all blob storage via BlobMapping.
 * In MediaLibrary mode, models handle storage directly via InteractsWithMediaLibrary.
 */
class BlobManager
{
    public function __construct(
        protected BlobStorage $storage,
        protected BlobDownloader $downloader,
        protected BlobUploader $uploader,
    ) {}

    /**
     * Get the configured storage driver.
     */
    public function storageDriver(): BlobStorageDriver
    {
        return config('atp-parity.blobs.storage_driver', BlobStorageDriver::Filesystem);
    }

    /**
     * Check if using filesystem storage driver.
     */
    public function usesFilesystem(): bool
    {
        return $this->storageDriver() === BlobStorageDriver::Filesystem;
    }

    /**
     * Check if using MediaLibrary storage driver.
     */
    public function usesMediaLibrary(): bool
    {
        return $this->storageDriver() === BlobStorageDriver::MediaLibrary;
    }

    /**
     * Download blob content from AT Protocol without storing.
     *
     * Use this to get raw content for custom handling (e.g., MediaLibrary).
     *
     * @throws \RuntimeException If download fails
     */
    public function downloadContent(BlobReference $blob, string $did): string
    {
        return $this->downloader->downloadContent($blob, $did);
    }

    /**
     * Download a blob from AT Protocol and store locally.
     *
     * Only available in Filesystem mode. In MediaLibrary mode, use
     * InteractsWithMediaLibrary::downloadAtpBlobToMedia() instead.
     *
     * @throws \RuntimeException If in MediaLibrary mode
     */
    public function download(BlobReference $blob, string $did): BlobMapping
    {
        return $this->downloader->download($blob, $did);
    }

    /**
     * Download multiple blobs.
     *
     * Only available in Filesystem mode.
     *
     * @param  array<BlobReference>  $blobs
     * @return array<BlobMapping>
     *
     * @throws \RuntimeException If in MediaLibrary mode
     */
    public function downloadMany(array $blobs, string $did): array
    {
        return $this->downloader->downloadMany($blobs, $did);
    }

    /**
     * Upload a file to AT Protocol.
     */
    public function upload(
        string $did,
        UploadedFile|SplFileInfo|string $file,
        ?string $mimeType = null
    ): BlobReference {
        return $this->uploader->upload($did, $file, $mimeType);
    }

    /**
     * Upload a file from a path.
     */
    public function uploadFromPath(string $did, string $path, ?string $mimeType = null): BlobReference
    {
        return $this->uploader->uploadFromPath($did, $path, $mimeType);
    }

    /**
     * Upload from raw content.
     */
    public function uploadFromContent(string $did, string $content, string $mimeType): BlobReference
    {
        return $this->uploader->uploadFromContent($did, $content, $mimeType);
    }

    /**
     * Get URL for a blob.
     *
     * Uses the configured URL strategy to generate the appropriate URL.
     * Falls back through strategies if local URL is not available.
     */
    public function url(BlobReference|string $blob, string $did): string
    {
        $cid = $blob instanceof BlobReference ? $blob->getCid() : $blob;
        $strategy = config('atp-parity.blobs.url_strategy', BlobUrlStrategy::Cdn);

        // Try local first if configured
        if ($strategy === BlobUrlStrategy::Local) {
            $localUrl = $this->localUrl($cid);
            if ($localUrl) {
                return $localUrl;
            }
        }

        // Use CDN or PDS URL
        return match ($strategy) {
            BlobUrlStrategy::Cdn => $this->cdnUrl($cid, $did),
            BlobUrlStrategy::Pds => $this->pdsUrl($cid, $did),
            default => $this->cdnUrl($cid, $did),
        };
    }

    /**
     * Get local URL for a blob if downloaded.
     */
    public function localUrl(string $cid): ?string
    {
        $mapping = BlobMapping::findByCid($cid);

        if ($mapping && $mapping->hasLocalFile()) {
            return $mapping->getLocalUrl();
        }

        return null;
    }

    /**
     * Get CDN URL for a blob (Bluesky CDN format).
     */
    public function cdnUrl(string $cid, string $did): string
    {
        $cdnBase = config('atp-parity.blobs.cdn_url', 'https://cdn.bsky.app');

        return "{$cdnBase}/img/feed_thumbnail/plain/{$did}/{$cid}@jpeg";
    }

    /**
     * Get full-size CDN URL for a blob.
     */
    public function cdnUrlFull(string $cid, string $did): string
    {
        $cdnBase = config('atp-parity.blobs.cdn_url', 'https://cdn.bsky.app');

        return "{$cdnBase}/img/feed_fullsize/plain/{$did}/{$cid}@jpeg";
    }

    /**
     * Get PDS URL for a blob.
     */
    public function pdsUrl(string $cid, string $did): string
    {
        $pds = Resolver::resolvePds($did) ?? 'https://bsky.social';

        return "{$pds}/xrpc/com.atproto.sync.getBlob?did={$did}&cid={$cid}";
    }

    /**
     * Check if blob exists locally.
     */
    public function existsLocally(string $cid): bool
    {
        return $this->storage->exists($cid);
    }

    /**
     * Get blob mapping by CID.
     */
    public function mapping(string $cid): ?BlobMapping
    {
        return BlobMapping::findByCid($cid);
    }

    /**
     * Sync blobs from a record - downloads any that aren't stored locally.
     *
     * @param  array<BlobReference>  $blobRefs
     */
    public function syncFromRecord(array $blobRefs, string $did): void
    {
        foreach ($blobRefs as $blob) {
            if (! $this->existsLocally($blob->getCid())) {
                try {
                    $this->download($blob, $did);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * Delete a blob from local storage.
     */
    public function delete(string $cid): bool
    {
        $mapping = BlobMapping::findByCid($cid);

        if ($mapping) {
            $mapping->deleteLocalFile();
            $mapping->delete();

            return true;
        }

        return $this->storage->delete($cid);
    }

    /**
     * Get blob content.
     */
    public function content(string $cid): ?string
    {
        return $this->storage->get($cid);
    }
}
