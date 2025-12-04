<?php

namespace SocialDept\AtpParity\Concerns;

use SocialDept\AtpParity\Blob\BlobManager;
use SocialDept\AtpParity\Blob\BlobMapping;
use SocialDept\AtpParity\Enums\BlobStorageDriver;
use SocialDept\AtpSchema\Data\BlobReference;

/**
 * Trait for Eloquent models that have AT Protocol blob fields.
 *
 * Models using this trait should have an `atp_blobs` JSON column
 * that stores blob metadata (CID, mimeType, size) for each blob field.
 *
 * In MediaLibrary storage mode, use InteractsWithMediaLibrary trait
 * instead for full blob handling support.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasAtpBlobs
{
    /**
     * Define which record fields contain blobs.
     * Override in your model.
     *
     * @return array<string, array{type: 'single'|'array'}>
     */
    public function atpBlobFields(): array
    {
        return [];
    }

    /**
     * Get URL for a single blob field.
     * Returns local URL if downloaded, remote URL otherwise.
     */
    public function getAtpBlobUrl(string $field): ?string
    {
        $blobData = $this->getAtpBlobData($field);

        if (! $blobData || ! isset($blobData['cid'])) {
            return null;
        }

        $did = $this->getAtpDid();
        if (! $did) {
            return null;
        }

        return app(BlobManager::class)->url($blobData['cid'], $did);
    }

    /**
     * Get URLs for an array blob field (like images).
     *
     * @return array<string>
     */
    public function getAtpBlobUrls(string $field): array
    {
        $blobsData = $this->getAtpBlobsData($field);

        if (empty($blobsData)) {
            return [];
        }

        $did = $this->getAtpDid();
        if (! $did) {
            return [];
        }

        $manager = app(BlobManager::class);

        return array_filter(array_map(
            fn ($blob) => isset($blob['cid']) ? $manager->url($blob['cid'], $did) : null,
            $blobsData
        ));
    }

    /**
     * Get full-size CDN URL for a blob field.
     */
    public function getAtpBlobUrlFull(string $field): ?string
    {
        $blobData = $this->getAtpBlobData($field);

        if (! $blobData || ! isset($blobData['cid'])) {
            return null;
        }

        $did = $this->getAtpDid();
        if (! $did) {
            return null;
        }

        return app(BlobManager::class)->cdnUrlFull($blobData['cid'], $did);
    }

    /**
     * Get full-size CDN URLs for an array blob field.
     *
     * @return array<string>
     */
    public function getAtpBlobUrlsFull(string $field): array
    {
        $blobsData = $this->getAtpBlobsData($field);

        if (empty($blobsData)) {
            return [];
        }

        $did = $this->getAtpDid();
        if (! $did) {
            return [];
        }

        $manager = app(BlobManager::class);

        return array_filter(array_map(
            fn ($blob) => isset($blob['cid']) ? $manager->cdnUrlFull($blob['cid'], $did) : null,
            $blobsData
        ));
    }

    /**
     * Get raw blob data for a field from stored JSON.
     */
    public function getAtpBlobData(string $field): ?array
    {
        $blobs = $this->getAttribute('atp_blobs');

        if (! is_array($blobs) || ! isset($blobs[$field])) {
            return null;
        }

        return $blobs[$field];
    }

    /**
     * Get array of blob data for a field.
     *
     * @return array<array{cid: string, mimeType: string, size: int}>
     */
    public function getAtpBlobsData(string $field): array
    {
        $data = $this->getAtpBlobData($field);

        if (! is_array($data)) {
            return [];
        }

        // Check if it's a single blob or array of blobs
        if (isset($data['cid'])) {
            return [$data];
        }

        return $data;
    }

    /**
     * Get all blob CIDs for this model.
     *
     * @return array<string>
     */
    public function getAtpBlobCids(): array
    {
        $cids = [];
        $blobs = $this->getAttribute('atp_blobs') ?? [];

        foreach ($blobs as $data) {
            if (is_array($data) && isset($data['cid'])) {
                $cids[] = $data['cid'];
            } elseif (is_array($data)) {
                foreach ($data as $item) {
                    if (isset($item['cid'])) {
                        $cids[] = $item['cid'];
                    }
                }
            }
        }

        return array_unique($cids);
    }

    /**
     * Store blob data from a BlobReference.
     */
    public function setAtpBlob(string $field, BlobReference $blob): void
    {
        $blobs = $this->getAttribute('atp_blobs') ?? [];

        $blobs[$field] = [
            'cid' => $blob->getCid(),
            'mimeType' => $blob->getMimeType(),
            'size' => $blob->getSize(),
        ];

        $this->setAttribute('atp_blobs', $blobs);
    }

    /**
     * Store multiple blobs for an array field.
     *
     * @param  array<BlobReference>  $blobRefs
     */
    public function setAtpBlobs(string $field, array $blobRefs): void
    {
        $blobs = $this->getAttribute('atp_blobs') ?? [];

        $blobs[$field] = array_map(fn (BlobReference $blob) => [
            'cid' => $blob->getCid(),
            'mimeType' => $blob->getMimeType(),
            'size' => $blob->getSize(),
        ], $blobRefs);

        $this->setAttribute('atp_blobs', $blobs);
    }

    /**
     * Clear blob data for a field.
     */
    public function clearAtpBlob(string $field): void
    {
        $blobs = $this->getAttribute('atp_blobs') ?? [];
        unset($blobs[$field]);
        $this->setAttribute('atp_blobs', $blobs);
    }

    /**
     * Download and cache all blobs for this model.
     *
     * Only available in Filesystem storage mode. In MediaLibrary mode,
     * use InteractsWithMediaLibrary::syncAtpBlobsToMedia() instead.
     *
     * @throws \RuntimeException If in MediaLibrary mode
     */
    public function downloadAtpBlobs(): void
    {
        $manager = app(BlobManager::class);

        if ($manager->usesMediaLibrary()) {
            throw new \RuntimeException(
                'downloadAtpBlobs() is not available in MediaLibrary mode. '.
                'Use InteractsWithMediaLibrary::syncAtpBlobsToMedia() instead.'
            );
        }

        $did = $this->getAtpDid();
        if (! $did) {
            return;
        }

        $blobs = $this->getAttribute('atp_blobs') ?? [];

        foreach ($blobs as $data) {
            if (is_array($data) && isset($data['cid'])) {
                $blob = new BlobReference($data['cid'], $data['mimeType'], $data['size']);
                $manager->download($blob, $did);
            } elseif (is_array($data)) {
                foreach ($data as $item) {
                    if (isset($item['cid'])) {
                        $blob = new BlobReference($item['cid'], $item['mimeType'], $item['size']);
                        $manager->download($blob, $did);
                    }
                }
            }
        }
    }

    /**
     * Check if all blobs are downloaded locally.
     *
     * In MediaLibrary mode, this always returns false since blobs
     * are stored in MediaLibrary collections, not the filesystem.
     * Use InteractsWithMediaLibrary methods to check MediaLibrary storage.
     */
    public function hasLocalBlobs(): bool
    {
        $manager = app(BlobManager::class);

        // In MediaLibrary mode, filesystem storage is not used
        if ($manager->usesMediaLibrary()) {
            return false;
        }

        foreach ($this->getAtpBlobCids() as $cid) {
            if (! $manager->existsLocally($cid)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if this model has any blob data.
     */
    public function hasAtpBlobs(): bool
    {
        return ! empty($this->getAtpBlobCids());
    }

    /**
     * Get the blob mappings for all blobs on this model.
     *
     * Only available in Filesystem storage mode. In MediaLibrary mode,
     * returns an empty collection since BlobMapping table is not used.
     *
     * @return \Illuminate\Support\Collection<int, BlobMapping>
     */
    public function getAtpBlobMappings()
    {
        $manager = app(BlobManager::class);

        // In MediaLibrary mode, BlobMapping table is not used
        if ($manager->usesMediaLibrary()) {
            return collect();
        }

        $cids = $this->getAtpBlobCids();

        if (empty($cids)) {
            return collect();
        }

        return BlobMapping::whereIn('cid', $cids)->get();
    }

    /**
     * Get the DID from the AT Protocol URI.
     * This method should be provided by HasAtpRecord trait.
     */
    abstract public function getAtpDid(): ?string;
}
