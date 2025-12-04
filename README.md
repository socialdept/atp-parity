[![Parity Header](./header.png)](https://github.com/socialdept/atp-parity)

<h3 align="center">
    Bidirectional mapping between AT Protocol records and Laravel Eloquent models.
</h3>

<p align="center">
    <br>
    <a href="https://packagist.org/packages/socialdept/atp-parity" title="Latest Version on Packagist"><img src="https://img.shields.io/packagist/v/socialdept/atp-parity.svg?style=flat-square"></a>
    <a href="https://packagist.org/packages/socialdept/atp-parity" title="Total Downloads"><img src="https://img.shields.io/packagist/dt/socialdept/atp-parity.svg?style=flat-square"></a>
    <a href="https://github.com/socialdept/atp-parity/actions/workflows/tests.yml" title="GitHub Tests Action Status"><img src="https://img.shields.io/github/actions/workflow/status/socialdept/atp-parity/tests.yml?branch=main&label=tests&style=flat-square"></a>
    <a href="LICENSE" title="Software License"><img src="https://img.shields.io/github/license/socialdept/atp-parity?style=flat-square"></a>
</p>

---

## What is Parity?

**Parity** is a Laravel package that bridges your Eloquent models with AT Protocol records. It provides bidirectional mapping, automatic firehose synchronization, and type-safe transformations between your database and the decentralized social web.

Think of it as Laravel's model casts, but for AT Protocol records.

## Why use Parity?

- **Laravel-style code** - Familiar patterns you already know
- **Bidirectional mapping** - Transform records to models and back
- **Firehose sync** - Automatically sync network events to your database
- **Type-safe DTOs** - Full integration with atp-schema generated types
- **Model traits** - Add AT Protocol awareness to any Eloquent model
- **Flexible mappers** - Define custom transformations for your domain
- **Blob handling** - Download, upload, and serve images and videos

## Quick Example

```php
use SocialDept\AtpParity\RecordMapper;
use SocialDept\AtpSchema\Data\Data;
use Illuminate\Database\Eloquent\Model;

class PostMapper extends RecordMapper
{
    public function recordClass(): string
    {
        return \SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post::class;
    }

    public function modelClass(): string
    {
        return \App\Models\Post::class;
    }

    protected function recordToAttributes(Data $record): array
    {
        return [
            'content' => $record->text,
            'published_at' => $record->createdAt,
        ];
    }

    protected function modelToRecordData(Model $model): array
    {
        return [
            'text' => $model->content,
            'createdAt' => $model->published_at->toIso8601String(),
        ];
    }
}
```

## Installation

```bash
composer require socialdept/atp-parity
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=parity-config
```

## Getting Started

Once installed, you're three steps away from syncing AT Protocol records:

### 1. Create a Mapper

Define how your record maps to your model:

```php
class PostMapper extends RecordMapper
{
    public function recordClass(): string
    {
        return Post::class; // Your atp-schema DTO or custom Record
    }

    public function modelClass(): string
    {
        return \App\Models\Post::class;
    }

    protected function recordToAttributes(Data $record): array
    {
        return ['content' => $record->text];
    }

    protected function modelToRecordData(Model $model): array
    {
        return ['text' => $model->content];
    }
}
```

### 2. Register Your Mapper

```php
// config/parity.php
return [
    'mappers' => [
        App\AtpMappers\PostMapper::class,
    ],
];
```

### 3. Add the Trait to Your Model

```php
use SocialDept\AtpParity\Concerns\HasAtpRecord;

class Post extends Model
{
    use HasAtpRecord;
}
```

Your model can now convert to/from AT Protocol records and query by URI.

## What can you build?

- **Data mirrors** - Keep local copies of AT Protocol data
- **AppViews** - Build custom applications with synced data
- **Analytics platforms** - Store and analyze network activity
- **Content aggregators** - Collect and organize posts locally
- **Moderation tools** - Track and manage content in your database
- **Hybrid applications** - Combine local and federated data

## Ecosystem Integration

Parity is designed to work seamlessly with the other atp-* packages:

| Package | Integration |
|---------|-------------|
| **atp-schema** | Records extend `Data`, use generated DTOs directly |
| **atp-client** | `RecordHelper` for fetching and hydrating records |
| **atp-signals** | `ParitySignal` for automatic firehose sync |

### Using with atp-schema

Use generated schema classes directly with `SchemaMapper`:

```php
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post;
use SocialDept\AtpParity\Support\SchemaMapper;

$mapper = new SchemaMapper(
    schemaClass: Post::class,
    modelClass: \App\Models\Post::class,
    toAttributes: fn(Post $p) => [
        'content' => $p->text,
        'published_at' => $p->createdAt,
    ],
    toRecordData: fn($m) => [
        'text' => $m->content,
        'createdAt' => $m->published_at->toIso8601String(),
    ],
);

$registry->register($mapper);
```

### Using with atp-client

Fetch records by URI and convert directly to models:

```php
use SocialDept\AtpParity\Support\RecordHelper;

$helper = app(RecordHelper::class);

// Fetch as typed DTO
$record = $helper->fetch('at://did:plc:xxx/app.bsky.feed.post/abc123');

// Fetch and convert to model (unsaved)
$post = $helper->fetchAsModel('at://did:plc:xxx/app.bsky.feed.post/abc123');

// Fetch and sync to database (upsert)
$post = $helper->sync('at://did:plc:xxx/app.bsky.feed.post/abc123');
```

The helper automatically resolves the DID to find the correct PDS endpoint, so it works with any AT Protocol server - not just Bluesky.

### Using with atp-signals

Enable automatic firehose synchronization by registering the `ParitySignal`:

```php
// config/signal.php
return [
    'signals' => [
        \SocialDept\AtpParity\Signals\ParitySignal::class,
    ],
];
```

Run `php artisan signal:consume` and your models will automatically sync with matching firehose events.

### Importing Historical Data

For existing records created before you started consuming the firehose:

```bash
# Import a user's records
php artisan parity:import did:plc:z72i7hdynmk6r22z27h6tvur

# Check import status
php artisan parity:import-status
```

Or programmatically:

```php
use SocialDept\AtpParity\Import\ImportService;

$service = app(ImportService::class);
$result = $service->importUser('did:plc:z72i7hdynmk6r22z27h6tvur');

echo "Synced {$result->recordsSynced} records";
```

## Documentation

For detailed documentation on specific topics:

- [Record Mappers](docs/mappers.md) - Creating and using mappers
- [Model Traits](docs/traits.md) - HasAtpRecord and SyncsWithAtp
- [Blob Handling](docs/blobs.md) - Downloading, uploading, and serving blobs
- [atp-schema Integration](docs/atp-schema-integration.md) - Using generated DTOs
- [atp-client Integration](docs/atp-client-integration.md) - RecordHelper and fetching
- [atp-signals Integration](docs/atp-signals-integration.md) - ParitySignal and firehose sync
- [Importing](docs/importing.md) - Syncing historical data

## Model Traits

### HasAtpRecord

Add AT Protocol awareness to your models:

```php
use SocialDept\AtpParity\Concerns\HasAtpRecord;

class Post extends Model
{
    use HasAtpRecord;

    protected $fillable = ['content', 'atp_uri', 'atp_cid'];
}
```

Available methods:

```php
// Get AT Protocol metadata
$post->getAtpUri();        // at://did:plc:xxx/app.bsky.feed.post/rkey
$post->getAtpCid();        // bafyre...
$post->getAtpDid();        // did:plc:xxx (extracted from URI)
$post->getAtpCollection(); // app.bsky.feed.post (extracted from URI)
$post->getAtpRkey();       // rkey (extracted from URI)

// Check sync status
$post->hasAtpRecord();     // true if synced

// Convert to record DTO
$record = $post->toAtpRecord();

// Query scopes
Post::withAtpRecord()->get();      // Only synced posts
Post::withoutAtpRecord()->get();   // Only unsynced posts
Post::whereAtpUri($uri)->first();  // Find by URI
```

### SyncsWithAtp

Extended trait for bidirectional sync tracking:

```php
use SocialDept\AtpParity\Concerns\SyncsWithAtp;

class Post extends Model
{
    use SyncsWithAtp;
}
```

Additional methods:

```php
// Track sync status
$post->getAtpSyncedAt();   // Last sync timestamp
$post->hasLocalChanges();  // True if updated since last sync

// Mark as synced
$post->markAsSynced($uri, $cid);

// Update from remote
$post->updateFromRecord($record, $uri, $cid);
```

### HasAtpBlobs

Add blob handling to models with images or other binary content:

```php
use SocialDept\AtpParity\Concerns\HasAtpRecord;
use SocialDept\AtpParity\Concerns\HasAtpBlobs;

class Post extends Model
{
    use HasAtpRecord, HasAtpBlobs;

    protected $casts = ['atp_blobs' => 'array'];
}
```

Available methods:

```php
// Get URLs for blobs
$url = $post->getAtpBlobUrl('avatar');     // Single blob URL
$urls = $post->getAtpBlobUrls('images');   // Array of URLs

// Download blobs locally
$post->downloadAtpBlobs();

// Check status
$post->hasAtpBlobs();      // Has any blob data
$post->hasLocalBlobs();    // All blobs downloaded locally
```

See [Blob Handling](docs/blobs.md) for complete documentation including MediaLibrary integration.

## Database Migration

Add AT Protocol columns to your models:

```php
Schema::table('posts', function (Blueprint $table) {
    $table->string('atp_uri')->nullable()->unique();
    $table->string('atp_cid')->nullable();
    $table->timestamp('atp_synced_at')->nullable(); // For SyncsWithAtp
    $table->json('atp_blobs')->nullable();          // For HasAtpBlobs
});
```

Publish and run Parity's migrations for import state tracking and blob mappings:

```bash
php artisan vendor:publish --tag=parity-migrations
php artisan migrate
```

**Note:** The `parity_blob_mappings` migration is only required if using the `filesystem` storage driver. If using `medialibrary` mode, you can skip this migration.

## Configuration

```php
// config/parity.php
return [
    // Registered mappers
    'mappers' => [
        App\AtpMappers\PostMapper::class,
        App\AtpMappers\ProfileMapper::class,
    ],

    // Column names for AT Protocol metadata
    'columns' => [
        'uri' => 'atp_uri',
        'cid' => 'atp_cid',
    ],

    // Blob handling configuration
    'blobs' => [
        // 'filesystem' (requires migrations) or 'medialibrary' (no extra migrations)
        'storage_driver' => \SocialDept\AtpParity\Enums\BlobStorageDriver::Filesystem,
        'download_on_import' => env('PARITY_BLOB_DOWNLOAD', false),
        'disk' => env('PARITY_BLOB_DISK', 'local'),
        'url_strategy' => \SocialDept\AtpParity\Enums\BlobUrlStrategy::Cdn,
    ],
];
```

## Creating Custom Records

Extend the `Record` base class for custom AT Protocol records:

```php
use SocialDept\AtpParity\Data\Record;
use Carbon\Carbon;

class PostRecord extends Record
{
    public function __construct(
        public readonly string $text,
        public readonly Carbon $createdAt,
        public readonly ?array $facets = null,
    ) {}

    public static function getLexicon(): string
    {
        return 'app.bsky.feed.post';
    }

    public static function fromArray(array $data): static
    {
        return new static(
            text: $data['text'],
            createdAt: Carbon::parse($data['createdAt']),
            facets: $data['facets'] ?? null,
        );
    }
}
```

The `Record` class extends `atp-schema`'s `Data` and implements `atp-client`'s `Recordable` interface, ensuring full compatibility with the ecosystem.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- [socialdept/atp-schema](https://github.com/socialdept/atp-schema) ^0.3
- [socialdept/atp-client](https://github.com/socialdept/atp-client) ^0.0
- [socialdept/atp-resolver](https://github.com/socialdept/atp-resolver) ^1.1
- [socialdept/atp-signals](https://github.com/socialdept/atp-signals) ^1.1

## Testing

```bash
composer test
```

## Resources

- [AT Protocol Documentation](https://atproto.com/)
- [Bluesky API Docs](https://docs.bsky.app/)
- [atp-schema](https://github.com/socialdept/atp-schema) - Generated AT Protocol DTOs
- [atp-client](https://github.com/socialdept/atp-client) - AT Protocol HTTP client
- [atp-signals](https://github.com/socialdept/atp-signals) - Firehose event consumer

## Support & Contributing

Found a bug or have a feature request? [Open an issue](https://github.com/socialdept/atp-parity/issues).

Want to contribute? Check out the [contribution guidelines](contributing.md).

## Changelog

Please see [changelog](changelog.md) for recent changes.

## Credits

- [Miguel Batres](https://batres.co) - founder & lead maintainer
- [All contributors](https://github.com/socialdept/atp-parity/graphs/contributors)

## License

Parity is open-source software licensed under the [MIT license](license.md).

---

**Built for the Federation** - By Social Dept.
