<?php

namespace SocialDept\AtpParity\Contracts;

/**
 * Contract for blob storage backends.
 */
interface BlobStorage
{
    /**
     * Store blob content.
     *
     * @param  string  $cid  The blob CID
     * @param  string  $content  The blob content
     * @param  string  $mimeType  The MIME type
     * @return string The storage path
     */
    public function store(string $cid, string $content, string $mimeType): string;

    /**
     * Retrieve blob content.
     *
     * @param  string  $cid  The blob CID
     * @return string|null The content or null if not found
     */
    public function get(string $cid): ?string;

    /**
     * Check if blob exists in storage.
     */
    public function exists(string $cid): bool;

    /**
     * Delete a stored blob.
     */
    public function delete(string $cid): bool;

    /**
     * Get the storage path for a blob.
     */
    public function path(string $cid): string;

    /**
     * Get a URL for the blob.
     *
     * @return string|null URL or null if not accessible via URL
     */
    public function url(string $cid): ?string;

    /**
     * Get the disk name being used.
     */
    public function disk(): string;
}
