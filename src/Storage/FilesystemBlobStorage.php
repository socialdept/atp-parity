<?php

namespace SocialDept\AtpParity\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use SocialDept\AtpParity\Contracts\BlobStorage;

/**
 * Filesystem-based blob storage using Laravel's Storage facade.
 */
class FilesystemBlobStorage implements BlobStorage
{
    protected string $diskName;

    protected string $basePath;

    public function __construct(?string $disk = null, ?string $basePath = null)
    {
        $this->diskName = $disk ?? config('parity.blobs.disk', 'local');
        $this->basePath = $basePath ?? config('parity.blobs.path', 'atp-blobs');
    }

    public function store(string $cid, string $content, string $mimeType): string
    {
        $path = $this->cidToPath($cid);
        $this->filesystem()->put($path, $content);

        return $path;
    }

    public function get(string $cid): ?string
    {
        $path = $this->cidToPath($cid);

        if (! $this->filesystem()->exists($path)) {
            return null;
        }

        return $this->filesystem()->get($path);
    }

    public function exists(string $cid): bool
    {
        return $this->filesystem()->exists($this->cidToPath($cid));
    }

    public function delete(string $cid): bool
    {
        return $this->filesystem()->delete($this->cidToPath($cid));
    }

    public function path(string $cid): string
    {
        return $this->cidToPath($cid);
    }

    public function url(string $cid): ?string
    {
        $path = $this->cidToPath($cid);

        if (! $this->exists($cid)) {
            return null;
        }

        try {
            return $this->filesystem()->url($path);
        } catch (\RuntimeException) {
            // Disk doesn't support URLs
            return null;
        }
    }

    public function disk(): string
    {
        return $this->diskName;
    }

    /**
     * Convert CID to storage path with directory sharding.
     *
     * Shards blobs into subdirectories based on CID prefix for
     * better filesystem performance with large numbers of files.
     *
     * Example: bafyreibxxx... -> atp-blobs/ba/fy/bafyreibxxx...
     */
    protected function cidToPath(string $cid): string
    {
        $prefix1 = substr($cid, 0, 2);
        $prefix2 = substr($cid, 2, 2);

        return "{$this->basePath}/{$prefix1}/{$prefix2}/{$cid}";
    }

    protected function filesystem(): Filesystem
    {
        return Storage::disk($this->diskName);
    }
}
