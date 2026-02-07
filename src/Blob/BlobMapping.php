<?php

namespace SocialDept\AtpParity\Blob;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use SocialDept\AtpParity\Enums\BlobSource;
use SocialDept\AtpSchema\Data\BlobReference;

/**
 * Tracks blob mappings between AT Protocol CIDs and local storage.
 *
 * @property int $id
 * @property string $cid
 * @property string $did
 * @property string $mime_type
 * @property int $size
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $media_id
 * @property \Carbon\Carbon|null $downloaded_at
 * @property \Carbon\Carbon|null $uploaded_at
 * @property BlobSource $source
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class BlobMapping extends Model
{
    protected $fillable = [
        'cid',
        'did',
        'mime_type',
        'size',
        'disk',
        'path',
        'media_id',
        'downloaded_at',
        'uploaded_at',
        'source',
    ];

    protected $casts = [
        'size' => 'integer',
        'downloaded_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'source' => BlobSource::class,
    ];

    public function getTable(): string
    {
        return config('atp-parity.blobs.table', 'parity_blob_mappings');
    }

    /**
     * Check if this blob has a local file.
     */
    public function hasLocalFile(): bool
    {
        if (! $this->disk || ! $this->path) {
            return false;
        }

        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Get the full path to the local file.
     */
    public function getFullPath(): ?string
    {
        if (! $this->disk || ! $this->path) {
            return null;
        }

        return Storage::disk($this->disk)->path($this->path);
    }

    /**
     * Get a URL to the local file.
     */
    public function getLocalUrl(): ?string
    {
        if (! $this->hasLocalFile()) {
            return null;
        }

        try {
            return Storage::disk($this->disk)->url($this->path);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Get the blob content.
     */
    public function getContent(): ?string
    {
        if (! $this->hasLocalFile()) {
            return null;
        }

        return Storage::disk($this->disk)->get($this->path);
    }

    /**
     * Delete the local file.
     */
    public function deleteLocalFile(): bool
    {
        if (! $this->disk || ! $this->path) {
            return false;
        }

        return Storage::disk($this->disk)->delete($this->path);
    }

    /**
     * Convert to a BlobReference.
     */
    public function toBlobReference(): BlobReference
    {
        return new BlobReference(
            ref: $this->cid,
            mimeType: $this->mime_type,
            size: $this->size,
        );
    }

    /**
     * Check if this is an image.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Check if this is a video.
     */
    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    /**
     * Scope to blobs for a specific DID.
     */
    public function scopeForDid(Builder $query, string $did): Builder
    {
        return $query->where('did', $did);
    }

    /**
     * Scope to downloaded blobs.
     */
    public function scopeDownloaded(Builder $query): Builder
    {
        return $query->whereNotNull('downloaded_at');
    }

    /**
     * Scope to uploaded blobs.
     */
    public function scopeUploaded(Builder $query): Builder
    {
        return $query->whereNotNull('uploaded_at');
    }

    /**
     * Scope to blobs from remote source.
     */
    public function scopeFromRemote(Builder $query): Builder
    {
        return $query->where('source', BlobSource::Remote);
    }

    /**
     * Scope to blobs from local source.
     */
    public function scopeFromLocal(Builder $query): Builder
    {
        return $query->where('source', BlobSource::Local);
    }

    /**
     * Scope to images.
     */
    public function scopeImages(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'image/%');
    }

    /**
     * Scope to videos.
     */
    public function scopeVideos(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'video/%');
    }

    /**
     * Find a blob mapping by CID.
     */
    public static function findByCid(string $cid): ?self
    {
        return static::where('cid', $cid)->first();
    }

    /**
     * Upsert a blob mapping.
     */
    public static function upsertMapping(array $attributes): self
    {
        return static::updateOrCreate(
            ['cid' => $attributes['cid']],
            $attributes
        );
    }
}
