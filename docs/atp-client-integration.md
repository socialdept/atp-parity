# atp-client Integration

Parity integrates with atp-client to fetch records from the AT Protocol network and convert them to Eloquent models. The `RecordHelper` class provides a simple interface for these operations.

## RecordHelper

The `RecordHelper` is registered as a singleton and available via the container:

```php
use SocialDept\AtpParity\Support\RecordHelper;

$helper = app(RecordHelper::class);
```

### How It Works

When you provide an AT Protocol URI, RecordHelper:

1. Parses the URI to extract the DID, collection, and rkey
2. Resolves the DID to find the user's PDS endpoint (via atp-resolver)
3. Creates a public client for that PDS
4. Fetches the record
5. Converts it using the registered mapper

This means it works with any AT Protocol server, not just Bluesky.

## Fetching Records

### `fetch(string $uri, ?string $recordClass = null): mixed`

Fetches a record and returns it as a typed DTO.

```php
use SocialDept\AtpParity\Support\RecordHelper;
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post;

$helper = app(RecordHelper::class);

// Auto-detect type from registered mapper
$record = $helper->fetch('at://did:plc:abc123/app.bsky.feed.post/xyz789');

// Or specify the class explicitly
$record = $helper->fetch(
    'at://did:plc:abc123/app.bsky.feed.post/xyz789',
    Post::class
);

// Access typed properties
echo $record->text;
echo $record->createdAt;
```

### `fetchAsModel(string $uri): ?Model`

Fetches a record and converts it to an Eloquent model (unsaved).

```php
$post = $helper->fetchAsModel('at://did:plc:abc123/app.bsky.feed.post/xyz789');

if ($post) {
    echo $post->content;
    echo $post->atp_uri;
    echo $post->atp_cid;

    // Save if you want to persist it
    $post->save();
}
```

Returns `null` if no mapper is registered for the collection.

### `sync(string $uri): ?Model`

Fetches a record and upserts it to the database.

```php
// Creates or updates the model
$post = $helper->sync('at://did:plc:abc123/app.bsky.feed.post/xyz789');

// Model is saved automatically
echo $post->id;
echo $post->content;
```

This is the most common method for syncing remote records to your database.

## Working with Responses

### `hydrateRecord(GetRecordResponse $response, ?string $recordClass = null): mixed`

If you already have a `GetRecordResponse` from atp-client, convert it to a typed DTO:

```php
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\Support\RecordHelper;

$helper = app(RecordHelper::class);

// Using atp-client directly
$client = Atp::public();
$response = $client->atproto->repo->getRecord(
    'did:plc:abc123',
    'app.bsky.feed.post',
    'xyz789'
);

// Convert to typed DTO
$record = $helper->hydrateRecord($response);
```

## Practical Examples

### Syncing a Single Post

```php
$helper = app(RecordHelper::class);

$uri = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3k2yihcrp6f2c';
$post = $helper->sync($uri);

echo "Synced: {$post->content}";
```

### Syncing Multiple Posts

```php
$helper = app(RecordHelper::class);

$uris = [
    'at://did:plc:abc/app.bsky.feed.post/123',
    'at://did:plc:def/app.bsky.feed.post/456',
    'at://did:plc:ghi/app.bsky.feed.post/789',
];

foreach ($uris as $uri) {
    try {
        $post = $helper->sync($uri);
        echo "Synced: {$post->id}\n";
    } catch (\Exception $e) {
        echo "Failed to sync {$uri}: {$e->getMessage()}\n";
    }
}
```

### Fetching for Preview (Without Saving)

```php
$helper = app(RecordHelper::class);

// Get model without saving
$post = $helper->fetchAsModel('at://did:plc:xxx/app.bsky.feed.post/abc');

if ($post) {
    return view('posts.preview', ['post' => $post]);
}

return abort(404);
```

### Checking if Record Exists Locally

```php
use App\Models\Post;
use SocialDept\AtpParity\Support\RecordHelper;

$uri = 'at://did:plc:xxx/app.bsky.feed.post/abc';

// Check local database first
$post = Post::whereAtpUri($uri)->first();

if (!$post) {
    // Not in database, fetch from network
    $helper = app(RecordHelper::class);
    $post = $helper->sync($uri);
}

return $post;
```

### Building a Post Importer

```php
namespace App\Services;

use SocialDept\AtpParity\Support\RecordHelper;
use SocialDept\AtpClient\Facades\Atp;

class PostImporter
{
    public function __construct(
        protected RecordHelper $helper
    ) {}

    /**
     * Import all posts from a user.
     */
    public function importUserPosts(string $did, int $limit = 100): array
    {
        $imported = [];
        $client = Atp::public();
        $cursor = null;

        do {
            $response = $client->atproto->repo->listRecords(
                repo: $did,
                collection: 'app.bsky.feed.post',
                limit: min($limit - count($imported), 100),
                cursor: $cursor
            );

            foreach ($response->records as $record) {
                $post = $this->helper->sync($record->uri);
                $imported[] = $post;

                if (count($imported) >= $limit) {
                    break 2;
                }
            }

            $cursor = $response->cursor;
        } while ($cursor && count($imported) < $limit);

        return $imported;
    }
}
```

## Error Handling

RecordHelper returns `null` for various failure conditions:

```php
$helper = app(RecordHelper::class);

// Invalid URI format
$result = $helper->fetch('not-a-valid-uri');
// Returns null

// No mapper registered for collection
$result = $helper->fetchAsModel('at://did:plc:xxx/some.unknown.collection/abc');
// Returns null

// PDS resolution failed
$result = $helper->fetch('at://did:plc:invalid/app.bsky.feed.post/abc');
// Returns null (or throws exception depending on resolver config)
```

For more control, catch exceptions:

```php
use SocialDept\AtpResolver\Exceptions\DidResolutionException;

try {
    $post = $helper->sync($uri);
} catch (DidResolutionException $e) {
    // DID could not be resolved
    Log::warning("Could not resolve DID for {$uri}");
} catch (\Exception $e) {
    // Network error, invalid response, etc.
    Log::error("Failed to sync {$uri}: {$e->getMessage()}");
}
```

## Performance Considerations

### PDS Client Caching

RecordHelper caches public clients by PDS endpoint:

```php
// First request to this PDS - creates client
$helper->sync('at://did:plc:abc/app.bsky.feed.post/1');

// Same PDS - reuses cached client
$helper->sync('at://did:plc:abc/app.bsky.feed.post/2');

// Different PDS - creates new client
$helper->sync('at://did:plc:xyz/app.bsky.feed.post/1');
```

### DID Resolution Caching

atp-resolver caches DID documents and PDS endpoints. Default TTL is 1 hour.

### Batch Operations

For bulk imports, consider using atp-client's `listRecords` directly and then batch-processing:

```php
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\MapperRegistry;

$client = Atp::public($pdsEndpoint);
$registry = app(MapperRegistry::class);
$mapper = $registry->forLexicon('app.bsky.feed.post');

$response = $client->atproto->repo->listRecords(
    repo: $did,
    collection: 'app.bsky.feed.post',
    limit: 100
);

foreach ($response->records as $record) {
    $recordClass = $mapper->recordClass();
    $dto = $recordClass::fromArray($record->value);

    $mapper->upsert($dto, [
        'uri' => $record->uri,
        'cid' => $record->cid,
    ]);
}
```

## Using with Authenticated Client

While RecordHelper uses public clients, you can also use authenticated clients for records that require auth:

```php
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\MapperRegistry;

// Authenticated client
$client = Atp::as('user.bsky.social');

// Fetch a record that requires auth
$response = $client->atproto->repo->getRecord(
    repo: $client->session()->did(),
    collection: 'app.bsky.feed.post',
    rkey: 'abc123'
);

// Convert using mapper
$registry = app(MapperRegistry::class);
$mapper = $registry->forLexicon('app.bsky.feed.post');

$recordClass = $mapper->recordClass();
$record = $recordClass::fromArray($response->value);

$model = $mapper->upsert($record, [
    'uri' => $response->uri,
    'cid' => $response->cid,
]);
```
