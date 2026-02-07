<?php

namespace SocialDept\AtpParity\Blob;

use Illuminate\Support\Facades\Http;
use SocialDept\AtpParity\Contracts\BlobStorage;
use SocialDept\AtpParity\Enums\BlobSource;
use SocialDept\AtpParity\Enums\BlobStorageDriver;
use SocialDept\AtpParity\Events\BlobDownloaded;
use SocialDept\AtpSupport\Facades\Resolver;
use SocialDept\AtpSchema\Data\BlobReference;
use Throwable;

/**
 * Downloads blobs from AT Protocol and stores them locally.
 */
class BlobDownloader
{
    public function __construct(
        protected BlobStorage $storage,
    ) {}

    /**
     * Download blob content from AT Protocol without storing.
     *
     * Returns the raw binary content. Use this when you want to handle
     * storage yourself (e.g., with MediaLibrary).
     *
     * @throws \RuntimeException If download fails
     */
    public function downloadContent(BlobReference $blob, string $did): string
    {
        $cid = $blob->getCid();

        // Check size limit
        $maxSize = config('atp-parity.blobs.max_download_size', 10 * 1024 * 1024);
        if ($blob->getSize() > $maxSize) {
            throw new \RuntimeException("Blob size ({$blob->getSize()}) exceeds maximum ({$maxSize})");
        }

        // Resolve PDS endpoint
        $pds = Resolver::resolvePds($did);
        if (! $pds) {
            throw new \RuntimeException("Could not resolve PDS for DID: {$did}");
        }

        // Download from PDS
        $url = "{$pds}/xrpc/com.atproto.sync.getBlob?did={$did}&cid={$cid}";

        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("Failed to download blob: HTTP {$response->status()}");
        }

        return $response->body();
    }

    /**
     * Download a blob from AT Protocol and store locally.
     *
     * In MediaLibrary mode, this method is not used - models handle
     * storage via InteractsWithMediaLibrary trait instead.
     *
     * @throws \RuntimeException If download fails or in medialibrary mode
     */
    public function download(BlobReference $blob, string $did): BlobMapping
    {
        $driver = config('atp-parity.blobs.storage_driver', BlobStorageDriver::Filesystem);

        if ($driver === BlobStorageDriver::MediaLibrary) {
            throw new \RuntimeException(
                'BlobDownloader::download() is not available in MediaLibrary mode. '.
                'Use InteractsWithMediaLibrary::downloadAtpBlobToMedia() instead.'
            );
        }

        $cid = $blob->getCid();

        // Check if already downloaded
        $existing = BlobMapping::findByCid($cid);
        if ($existing && $existing->hasLocalFile()) {
            return $existing;
        }

        $content = $this->downloadContent($blob, $did);

        // Store locally
        $path = $this->storage->store($cid, $content, $blob->getMimeType());

        // Create or update mapping
        $mapping = BlobMapping::upsertMapping([
            'cid' => $cid,
            'did' => $did,
            'mime_type' => $blob->getMimeType(),
            'size' => $blob->getSize(),
            'disk' => $this->storage->disk(),
            'path' => $path,
            'downloaded_at' => now(),
            'source' => BlobSource::Remote,
        ]);

        event(new BlobDownloaded($mapping, $blob));

        return $mapping;
    }

    /**
     * Download multiple blobs.
     *
     * @param  array<BlobReference>  $blobs
     * @return array<BlobMapping>
     */
    public function downloadMany(array $blobs, string $did): array
    {
        $results = [];

        foreach ($blobs as $blob) {
            try {
                $results[] = $this->download($blob, $did);
            } catch (Throwable $e) {
                // Log but continue with others
                report($e);
            }
        }

        return $results;
    }

    /**
     * Check if a blob exists locally.
     */
    public function existsLocally(string $cid): bool
    {
        return $this->storage->exists($cid);
    }
}
