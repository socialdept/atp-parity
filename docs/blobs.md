# Blob Handling

Parity provides comprehensive blob handling for AT Protocol images, videos, and other binary content. You can download blobs from the network, upload local files to AT Protocol, and integrate with Spatie MediaLibrary for advanced media management.

## Overview

AT Protocol blobs are binary content (images, videos, etc.) stored separately from records. Each blob is identified by a CID (Content Identifier) and referenced within records. Parity's blob system enables:

- **Download blobs** from AT Protocol to local storage
- **Upload blobs** from local files to AT Protocol
- **URL generation** via CDN, PDS, or local storage
- **Automatic sync** during record imports
- **MediaLibrary integration** (optional or exclusive)

## Storage Drivers

Parity supports two storage drivers for blobs:

| Driver | Description | Migrations Required |
|--------|-------------|---------------------|
| `Filesystem` | Stores blobs on Laravel filesystem with `parity_blob_mappings` table | Yes |
| `MediaLibrary` | Stores blobs via Spatie MediaLibrary only | No (uses MediaLibrary tables) |

### Filesystem Mode (Default)

Uses Laravel's filesystem abstraction with a dedicated `parity_blob_mappings` table to track blob metadata. Best for:

- Simple setups without MediaLibrary
- Applications that need direct filesystem access to blobs
- Deduplication across multiple models

### MediaLibrary Mode

Uses Spatie MediaLibrary exclusively for blob storage. No Parity-specific migrations required. Best for:

- Applications already using MediaLibrary
- When you want blobs attached directly to models
- Avoiding extra database tables

```env
PARITY_BLOB_STORAGE=medialibrary
```

## Quick Start

### Filesystem Mode

#### 1. Run the Migration

```bash
php artisan vendor:publish --tag=parity-migrations
php artisan migrate
```

#### 2. Configure Blob Storage

```php
// config/parity.php
'blobs' => [
    'storage_driver' => \SocialDept\AtpParity\Enums\BlobStorageDriver::Filesystem,
    'disk' => 'local',
    'path' => 'atp-blobs',
    'url_strategy' => \SocialDept\AtpParity\Enums\BlobUrlStrategy::Cdn,
],
```

#### 3. Add the Trait to Your Model

```php
use SocialDept\AtpParity\Concerns\HasAtpRecord;
use SocialDept\AtpParity\Concerns\HasAtpBlobs;

class Post extends Model
{
    use HasAtpRecord, HasAtpBlobs;

    protected $casts = [
        'atp_blobs' => 'array',
    ];
}
```

#### 4. Use Blob URLs in Views

```blade
@foreach($post->getAtpBlobUrls('images') as $url)
    <img src="{{ $url }}" alt="">
@endforeach
```

### MediaLibrary Mode

#### 1. Install MediaLibrary

```bash
composer require spatie/laravel-medialibrary
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
php artisan migrate
```

#### 2. Configure Storage Driver

```php
// config/parity.php
'blobs' => [
    'storage_driver' => \SocialDept\AtpParity\Enums\BlobStorageDriver::MediaLibrary,
],
```

Or via environment:

```env
PARITY_BLOB_STORAGE=medialibrary
```

#### 3. Add the Trait to Your Model

```php
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use SocialDept\AtpParity\Concerns\InteractsWithMediaLibrary;

class Post extends Model implements HasMedia
{
    use InteractsWithMedia, InteractsWithMediaLibrary;

    protected $casts = [
        'atp_blobs' => 'array',
    ];

    public function atpBlobToMediaCollections(): array
    {
        return [
            'images' => 'post-images',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('post-images');
    }
}
```

#### 4. Download Blobs to MediaLibrary

```php
// Sync all blobs from atp_blobs to MediaLibrary collections
$post->syncAtpBlobsToMedia();

// Or download a specific blob
$post->downloadAtpBlobToMedia($blobReference, 'post-images');
```

#### 5. Use MediaLibrary URLs in Views

```blade
@foreach($post->getMedia('post-images') as $media)
    <img src="{{ $media->getUrl() }}" alt="">
@endforeach
```

## BlobManager

The `BlobManager` is the central service for all blob operations:

```php
use SocialDept\AtpParity\Blob\BlobManager;

$manager = app(BlobManager::class);

// Download a blob
$mapping = $manager->download($blobReference, $did);

// Upload a file
$blobRef = $manager->upload($did, $request->file('image'));

// Get URL for a blob
$url = $manager->url($cid, $did);

// Check if blob exists locally
$exists = $manager->existsLocally($cid);
```

### Downloading Blobs

```php
use SocialDept\AtpSchema\Data\BlobReference;

// Download a single blob
$blob = new BlobReference($cid, 'image/jpeg', 245000);
$mapping = $manager->download($blob, $did);

// Download multiple blobs
$mappings = $manager->downloadMany($blobs, $did);

// Sync blobs from a record (skips already downloaded)
$manager->syncFromRecord($blobRefs, $did);
```

### Uploading Blobs

```php
// Upload from request file
$blobRef = $manager->upload($did, $request->file('avatar'));

// Upload from path
$blobRef = $manager->uploadFromPath($did, '/path/to/image.jpg');

// Upload from content
$blobRef = $manager->uploadFromContent($did, $imageData, 'image/png');
```

### URL Generation

Parity supports multiple URL strategies:

```php
use SocialDept\AtpParity\Enums\BlobUrlStrategy;

// Uses configured strategy (default: CDN)
$url = $manager->url($cid, $did);

// Force specific strategies
$cdnUrl = $manager->cdnUrl($cid, $did);         // Bluesky CDN (thumbnail)
$fullUrl = $manager->cdnUrlFull($cid, $did);    // Bluesky CDN (full size)
$pdsUrl = $manager->pdsUrl($cid, $did);         // Direct PDS endpoint
$localUrl = $manager->localUrl($cid);           // Local storage (if downloaded)
```

## HasAtpBlobs Trait

Add blob awareness to your Eloquent models:

```php
use SocialDept\AtpParity\Concerns\HasAtpRecord;
use SocialDept\AtpParity\Concerns\HasAtpBlobs;

class Post extends Model
{
    use HasAtpRecord, HasAtpBlobs;

    protected $casts = [
        'atp_blobs' => 'array',
    ];

    // Define blob fields (optional, for documentation)
    public function atpBlobFields(): array
    {
        return [
            'images' => ['type' => 'array'],
            'thumbnail' => ['type' => 'single'],
        ];
    }
}
```

### Available Methods

```php
// Get URLs
$url = $post->getAtpBlobUrl('avatar');           // Single blob URL
$urls = $post->getAtpBlobUrls('images');         // Array of URLs
$fullUrl = $post->getAtpBlobUrlFull('avatar');   // Full-size CDN URL
$fullUrls = $post->getAtpBlobUrlsFull('images'); // Full-size URLs

// Get blob data
$data = $post->getAtpBlobData('avatar');         // {cid, mimeType, size}
$dataArray = $post->getAtpBlobsData('images');   // Array of blob data

// Get all CIDs
$cids = $post->getAtpBlobCids();

// Set blobs from BlobReferences
$post->setAtpBlob('avatar', $blobRef);
$post->setAtpBlobs('images', $blobRefs);
$post->clearAtpBlob('avatar');

// Download all blobs locally
$post->downloadAtpBlobs();

// Check status
$hasBlobs = $post->hasAtpBlobs();
$hasLocal = $post->hasLocalBlobs();

// Get blob mappings
$mappings = $post->getAtpBlobMappings();
```

### Database Migration

Add an `atp_blobs` JSON column to store blob metadata:

```php
Schema::table('posts', function (Blueprint $table) {
    $table->json('atp_blobs')->nullable();
});
```

The column stores data like:

```json
{
    "images": [
        {"cid": "bafyrei...", "mimeType": "image/jpeg", "size": 245000},
        {"cid": "bafyrei...", "mimeType": "image/png", "size": 180000}
    ],
    "avatar": {"cid": "bafyrei...", "mimeType": "image/jpeg", "size": 50000}
}
```

## Mapper Integration

Define blob fields in your mapper to enable automatic extraction:

```php
class PostMapper extends RecordMapper
{
    public function recordClass(): string
    {
        return Post::class;
    }

    public function modelClass(): string
    {
        return \App\Models\Post::class;
    }

    // Define which record fields contain blobs
    public function blobFields(): array
    {
        return [
            'images' => [
                'type' => 'array',
                'path' => 'embed.images.*.image',
            ],
        ];
    }

    protected function recordToAttributes(Data $record): array
    {
        $attrs = [
            'content' => $record->text,
        ];

        // Store blob metadata
        if ($record->embed?->images) {
            $attrs['atp_blobs'] = [
                'images' => array_map(fn($img) => [
                    'cid' => $img->image->getCid(),
                    'mimeType' => $img->image->getMimeType(),
                    'size' => $img->image->getSize(),
                    'alt' => $img->alt,
                ], $record->embed->images),
            ];
        }

        return $attrs;
    }
}
```

### Extracting Blobs

```php
$mapper = app(MapperRegistry::class)->forLexicon('app.bsky.feed.post');

// Extract blob references from a record
$blobs = $mapper->extractBlobs($record);

// Check if mapper has blob fields
if ($mapper->hasBlobFields()) {
    // Handle blobs
}
```

## Automatic Download on Import

Enable automatic blob downloading during record imports:

```php
// config/parity.php
'blobs' => [
    'download_on_import' => true,
],
```

Or via environment variable:

```env
PARITY_BLOB_DOWNLOAD=true
```

When enabled, the `ImportService` will automatically download blobs for records that have blob fields defined in their mapper.

**Note:** Automatic blob downloads only work in Filesystem mode. In MediaLibrary mode, you must manually call `syncAtpBlobsToMedia()` on each model after import, since MediaLibrary requires attaching blobs to specific model instances.

## MediaLibrary Integration

For advanced media management, integrate with [Spatie MediaLibrary](https://spatie.be/docs/laravel-medialibrary):

```php
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use SocialDept\AtpParity\Concerns\InteractsWithMediaLibrary;

class Profile extends Model implements HasMedia
{
    use InteractsWithMedia, InteractsWithMediaLibrary;

    // Map blob fields to MediaLibrary collections
    public function atpBlobToMediaCollections(): array
    {
        return [
            'avatar' => 'avatar',
            'banner' => 'banner',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
        $this->addMediaCollection('banner')->singleFile();
    }
}
```

### Syncing Blobs to MediaLibrary

```php
// Download AT Protocol blobs and add to MediaLibrary collections
$profile->syncAtpBlobsToMedia();

// Access via MediaLibrary
$avatarUrl = $profile->getFirstMediaUrl('avatar');
```

### Uploading MediaLibrary Items to AT Protocol

```php
// Upload all items from a collection
$blobRefs = $profile->uploadMediaToAtp('avatar', $did);

// The media items are tagged with the ATP CID
$media = $profile->getFirstMedia('avatar');
$cid = $media->getCustomProperty('atp_cid');
```

### Finding Media by CID

```php
// Find media item by AT Protocol CID
$media = $profile->getMediaByCid($cid);
$media = $profile->getMediaByCid($cid, 'avatar'); // In specific collection

// Check if blob is in collection
if ($profile->hasBlobInCollection($cid, 'avatar')) {
    // Already synced
}
```

## Configuration

Full blob configuration options:

```php
// config/parity.php
use SocialDept\AtpParity\Enums\BlobStorageDriver;
use SocialDept\AtpParity\Enums\BlobUrlStrategy;

'blobs' => [
    // Storage driver: 'filesystem' or 'medialibrary'
    'storage_driver' => BlobStorageDriver::tryFrom(
        env('PARITY_BLOB_STORAGE', 'filesystem')
    ) ?? BlobStorageDriver::Filesystem,

    // Automatically download blobs when importing records (filesystem mode only)
    'download_on_import' => env('PARITY_BLOB_DOWNLOAD', false),

    // Laravel filesystem disk for storing blobs (filesystem mode only)
    'disk' => env('PARITY_BLOB_DISK', 'local'),

    // Base path within the disk (filesystem mode only)
    'path' => 'atp-blobs',

    // Maximum blob size to download (bytes)
    'max_download_size' => 10 * 1024 * 1024, // 10MB

    // URL generation strategy: Local, Cdn, or Pds
    'url_strategy' => BlobUrlStrategy::tryFrom(
        env('PARITY_BLOB_URL_STRATEGY', 'cdn')
    ) ?? BlobUrlStrategy::Cdn,

    // CDN base URL (for Bluesky)
    'cdn_url' => 'https://cdn.bsky.app',

    // Database table for blob mappings (filesystem mode only)
    'table' => 'parity_blob_mappings',

    // MediaLibrary collection prefix (medialibrary mode only)
    'media_collection_prefix' => 'atp_',
],
```

### URL Strategies

| Strategy | Description | Best For |
|----------|-------------|----------|
| `Cdn` | Uses Bluesky CDN URLs | Production, fastest delivery |
| `Pds` | Direct PDS getBlob endpoint | Non-Bluesky AT Protocol servers |
| `Local` | Serves from local storage | Self-hosted, offline access |

## Events

Blob operations dispatch events you can listen to. These events are dispatched in Filesystem mode only:

### BlobDownloaded

```php
use SocialDept\AtpParity\Events\BlobDownloaded;

Event::listen(BlobDownloaded::class, function (BlobDownloaded $event) {
    Log::info("Downloaded blob", [
        'cid' => $event->blob->getCid(),
        'size' => $event->blob->getSize(),
        'path' => $event->mapping->path,
    ]);
});
```

### BlobUploaded

```php
use SocialDept\AtpParity\Events\BlobUploaded;

Event::listen(BlobUploaded::class, function (BlobUploaded $event) {
    Log::info("Uploaded blob", [
        'cid' => $event->blob->getCid(),
        'did' => $event->mapping->did,
    ]);
});
```

## BlobMapping Model

The `BlobMapping` model tracks blob storage in Filesystem mode. This model is not used in MediaLibrary mode.

```php
use SocialDept\AtpParity\Blob\BlobMapping;

// Find by CID
$mapping = BlobMapping::findByCid($cid);

// Query blobs
$mappings = BlobMapping::forDid($did)->images()->get();
$downloaded = BlobMapping::downloaded()->get();
$uploaded = BlobMapping::uploaded()->get();

// Check local file
if ($mapping->hasLocalFile()) {
    $content = $mapping->getContent();
    $path = $mapping->getFullPath();
    $url = $mapping->getLocalUrl();
}

// Convert to BlobReference
$blobRef = $mapping->toBlobReference();

// Delete local file
$mapping->deleteLocalFile();
```

### Database Schema

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| cid | string | Blob CID (unique) |
| did | string | Owner DID |
| mime_type | string | Content type |
| size | bigint | Size in bytes |
| disk | string | Laravel disk name |
| path | string | Path within disk |
| media_id | string | MediaLibrary ID (optional) |
| downloaded_at | timestamp | When downloaded |
| uploaded_at | timestamp | When uploaded |
| source | string | 'remote' or 'local' |

## Publishing with Blobs

When publishing records with blobs:

```php
use SocialDept\AtpParity\Blob\BlobManager;
use SocialDept\AtpParity\Publish\PublishService;

$blobManager = app(BlobManager::class);
$publishService = app(PublishService::class);

// 1. Upload the image first
$blobRef = $blobManager->upload($did, $request->file('image'));

// 2. Create the post with blob reference
$post = Post::create([
    'content' => 'Check out this image!',
    'atp_blobs' => [
        'images' => [[
            'cid' => $blobRef->getCid(),
            'mimeType' => $blobRef->getMimeType(),
            'size' => $blobRef->getSize(),
            'alt' => 'My uploaded image',
        ]],
    ],
]);

// 3. Publish to AT Protocol
$result = $publishService->publishAs($did, $post);
```

## Storage Considerations

### Disk Configuration

For production, consider using cloud storage:

```php
// config/filesystems.php
'disks' => [
    'atp-blobs' => [
        'driver' => 's3',
        'bucket' => env('ATP_BLOB_BUCKET'),
        // ... S3 config
    ],
],

// config/parity.php
'blobs' => [
    'disk' => 'atp-blobs',
],
```

### Directory Sharding

Blobs are stored with directory sharding to prevent filesystem performance issues:

```
atp-blobs/
  ba/
    fy/
      bafyrei123...
      bafyrei456...
  qa/
    bc/
      qabcdef789...
```

### Deduplication

Blobs are deduplicated by CID. Uploading the same content twice will reuse the existing mapping.
