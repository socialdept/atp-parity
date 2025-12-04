<?php

namespace SocialDept\AtpParity\Blob;

use Illuminate\Http\UploadedFile;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\Contracts\BlobStorage;
use SocialDept\AtpParity\Enums\BlobSource;
use SocialDept\AtpParity\Enums\BlobStorageDriver;
use SocialDept\AtpParity\Events\BlobUploaded;
use SocialDept\AtpSchema\Data\BlobReference;
use SplFileInfo;

/**
 * Uploads blobs to AT Protocol and tracks them locally.
 */
class BlobUploader
{
    public function __construct(
        protected BlobStorage $storage,
    ) {}

    /**
     * Upload a file to AT Protocol.
     *
     * @param  string  $did  The DID to upload as
     * @param  UploadedFile|SplFileInfo|string  $file  The file to upload (path, uploaded file, or content)
     * @param  string|null  $mimeType  MIME type (auto-detected if not provided)
     * @param  bool  $skipLocalStorage  Skip local storage (for MediaLibrary mode)
     *
     * @throws \RuntimeException If upload fails
     */
    public function upload(
        string $did,
        UploadedFile|SplFileInfo|string $file,
        ?string $mimeType = null,
        bool $skipLocalStorage = false
    ): BlobReference {
        $client = Atp::as($did);

        // Upload via atp-client
        $blobRef = $client->atproto->repo->uploadBlob($file, $mimeType);

        // In MediaLibrary mode or when explicitly skipped, don't create local mapping
        $driver = config('parity.blobs.storage_driver', BlobStorageDriver::Filesystem);
        if ($skipLocalStorage || $driver === BlobStorageDriver::MediaLibrary) {
            return $blobRef;
        }

        // Store locally for deduplication and backup
        $content = $this->getFileContent($file);
        $path = $this->storage->store($blobRef->getCid(), $content, $blobRef->getMimeType());

        // Track mapping
        $mapping = BlobMapping::upsertMapping([
            'cid' => $blobRef->getCid(),
            'did' => $did,
            'mime_type' => $blobRef->getMimeType(),
            'size' => $blobRef->getSize(),
            'disk' => $this->storage->disk(),
            'path' => $path,
            'uploaded_at' => now(),
            'source' => BlobSource::Local,
        ]);

        event(new BlobUploaded($mapping, $blobRef));

        return $blobRef;
    }

    /**
     * Upload a file from a local path.
     */
    public function uploadFromPath(string $did, string $path, ?string $mimeType = null): BlobReference
    {
        return $this->upload($did, new SplFileInfo($path), $mimeType);
    }

    /**
     * Upload from raw content.
     */
    public function uploadFromContent(string $did, string $content, string $mimeType): BlobReference
    {
        return $this->upload($did, $content, $mimeType);
    }

    /**
     * Get file content from various input types.
     */
    protected function getFileContent(UploadedFile|SplFileInfo|string $file): string
    {
        if ($file instanceof UploadedFile) {
            return $file->getContent();
        }

        if ($file instanceof SplFileInfo) {
            return file_get_contents($file->getRealPath());
        }

        return $file;
    }
}
