# Model Traits

Part of the [atp-parity docs](../introduction.md#documentation). The `HasAtpRecord` and `SyncsWithAtp` traits, their methods and query scopes.

## HasAtpRecord

The base trait for models that store AT Protocol record references.

### Setup

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\HasAtpRecord;

class Post extends Model
{
    use HasAtpRecord;

    protected $fillable = [
        'content',
        'published_at',
        'atp_uri',
        'atp_cid',
    ];
}
```

### Database Migration

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->text('content');
    $table->timestamp('published_at');
    $table->string('atp_uri')->nullable()->unique();
    $table->string('atp_cid')->nullable();
    $table->timestamp('atp_synced_at')->nullable();
    $table->timestamps();
});
```

The `atp_synced_at` column is needed even with `HasAtpRecord` alone, because the mapper sets it on every model it creates or updates from a record. The `$table->atp()` Blueprint macro adds all three columns, using the names from `atp-parity.columns`.

### Available Methods

#### `getAtpUri(): ?string`

Returns the stored AT Protocol URI.

```php
$post->getAtpUri();
// "at://did:plc:abc123/app.bsky.feed.post/xyz789"
```

#### `getAtpCid(): ?string`

Returns the stored content identifier.

```php
$post->getAtpCid();
// "bafyreib2rxk3rjnlvzj..."
```

#### `getAtpDid(): ?string`

Extracts the DID from the URI.

```php
$post->getAtpDid();
// "did:plc:abc123"
```

#### `getAtpCollection(): ?string`

Extracts the collection (lexicon NSID) from the URI.

```php
$post->getAtpCollection();
// "app.bsky.feed.post"
```

#### `getAtpRkey(): ?string`

Extracts the record key from the URI.

```php
$post->getAtpRkey();
// "xyz789"
```

#### `getDesiredAtpRkey(): ?string`

Returns the rkey to use when the record is first created. The default returns `null`, which lets the PDS generate one. Override it to supply your own rkeys, such as UUIDs or slugs.

```php
public function getDesiredAtpRkey(): ?string
{
    return $this->slug;
}
```

#### `hasAtpRecord(): bool`

Checks if the model has been synced to AT Protocol, meaning the URI column is not null.

```php
if ($post->hasAtpRecord()) {
    // Model exists on AT Protocol
}
```

#### `getAtpMapper(): ?RecordMapper`

Gets the registered mapper for this model class. When several mappers are registered for the model, it returns the first one that is not a reference mapper, and falls back to the first mapper.

```php
$mapper = $post->getAtpMapper();
```

#### `toAtpRecord(): ?Data`

Converts the model to an AT Protocol record DTO. Returns `null` when no mapper is registered for the model.

```php
$record = $post->toAtpRecord();
$data = $record->toArray(); // Ready for API calls
```

### Query Scopes

#### `scopeWithAtpRecord($query)`

Query only models that have been synced.

```php
$syncedPosts = Post::withAtpRecord()->get();
```

#### `scopeWithoutAtpRecord($query)`

Query only models that have NOT been synced.

```php
$localOnlyPosts = Post::withoutAtpRecord()->get();
```

#### `scopeWhereAtpUri($query, string $uri)`

Find a model by its AT Protocol URI.

```php
$post = Post::whereAtpUri('at://did:plc:xxx/app.bsky.feed.post/abc')->first();
```

## SyncsWithAtp

Extended trait for bidirectional synchronization tracking. It uses `HasAtpRecord`, so it includes all of its methods and scopes, and adds sync timestamp tracking.

### Setup

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\SyncsWithAtp;

class Post extends Model
{
    use SyncsWithAtp;

    protected $fillable = [
        'content',
        'published_at',
        'atp_uri',
        'atp_cid',
        'atp_synced_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'atp_synced_at' => 'datetime',
    ];
}
```

### Database Migration

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->text('content');
    $table->timestamp('published_at');
    $table->string('atp_uri')->nullable()->unique();
    $table->string('atp_cid')->nullable();
    $table->timestamp('atp_synced_at')->nullable();
    $table->timestamps();
});
```

### Additional Methods

#### `getAtpSyncedAtColumn(): string`

Returns the column name for the sync timestamp. It returns `'atp_synced_at'` and does not read `atp-parity.columns.synced_at`. Override to customize.

```php
public function getAtpSyncedAtColumn(): string
{
    return 'last_synced_at'; // Default: 'atp_synced_at'
}
```

#### `getAtpSyncedAt(): ?DateTimeInterface`

Returns when the model was last synced.

```php
$syncedAt = $post->getAtpSyncedAt();
// Carbon instance or null
```

#### `markAsSynced(string $uri, string $cid): void`

Sets the URI and CID columns and sets the sync timestamp to now. Does not save.

```php
$post->markAsSynced($uri, $cid);
$post->save();
```

#### `hasLocalChanges(): bool`

Checks if the model has been modified since the last sync.

```php
if ($post->hasLocalChanges()) {
    // Local changes exist that haven't been pushed
}
```

This compares `updated_at` with the sync timestamp. It returns `true` when the model has never been synced, and `false` when `updated_at` is empty.

#### `updateFromRecord(Data $record, string $uri, string $cid): void`

Updates the model from a remote record through its mapper's `updateModel()` and sets the sync timestamp to now. Does nothing when no mapper is registered. Does not save.

```php
$post->updateFromRecord($record, $uri, $cid);
$post->save();
```

## Practical Examples

### Checking Sync Status

```php
$post = Post::find(1);

if (!$post->hasAtpRecord()) {
    echo "Not yet published to AT Protocol";
} elseif ($post->hasLocalChanges()) {
    echo "Has unpushed local changes";
} else {
    echo "In sync with AT Protocol";
}
```

### Finding Related Records

```php
// Get all posts from the same author
$authorDid = $post->getAtpDid();
$authorPosts = Post::withAtpRecord()
    ->get()
    ->filter(fn($p) => $p->getAtpDid() === $authorDid);
```

### Building an AT Protocol URL

```php
$post = Post::find(1);

if ($post->hasAtpRecord()) {
    $bskyUrl = sprintf(
        'https://bsky.app/profile/%s/post/%s',
        $post->getAtpDid(),
        $post->getAtpRkey()
    );
}
```

### Sync Status Dashboard

```php
// Get sync statistics
$stats = [
    'total' => Post::count(),
    'synced' => Post::withAtpRecord()->count(),
    'pending' => Post::withoutAtpRecord()->count(),
    'with_changes' => Post::withAtpRecord()
        ->get()
        ->filter(fn($p) => $p->hasLocalChanges())
        ->count(),
];
```

## Custom Column Names

Both traits read the URI and CID column names from the global column configuration:

```php
// config/atp-parity.php
return [
    'columns' => [
        'uri' => 'at_protocol_uri',
        'cid' => 'at_protocol_cid',
    ],
];
```

For the sync timestamp column, the trait does not read `atp-parity.columns.synced_at`, so override the method in your model. The mapper does read that config key, so set both to the same name:

```php
class Post extends Model
{
    use SyncsWithAtp;

    public function getAtpSyncedAtColumn(): string
    {
        return 'last_synced_at';
    }
}
```

## Event Hooks

The `SyncsWithAtp` trait has a `bootSyncsWithAtp()` method, but it registers nothing. Add your own model event listeners in the model's `booted()` method:

```php
class Post extends Model
{
    use SyncsWithAtp;

    protected static function booted(): void
    {
        static::updating(function ($model) {
            // Custom logic before updates
        });
    }
}
```

## Combining with Other Traits

The traits work alongside other Eloquent features:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use SocialDept\AtpParity\Concerns\SyncsWithAtp;

class Post extends Model
{
    use SoftDeletes;
    use SyncsWithAtp;

    // Both traits work together
}
```
