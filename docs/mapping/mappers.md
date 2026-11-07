# Record Mappers

Part of the [atp-parity docs](../introduction.md#documentation). Writing a `RecordMapper`, the methods it inherits, registering it and using the registry.

Mappers are the core of atp-parity. They define the transformation in both directions between AT Protocol record DTOs and Eloquent models.

## Creating a Mapper

Extend the `RecordMapper` abstract class and implement the required methods:

```php
<?php

namespace App\AtpMappers;

use App\Models\Post;
use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Acceptance\Acceptance;
use SocialDept\AtpParity\RecordMapper;
use SocialDept\AtpSchema\Data\Data;
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post as PostRecord;

/**
 * @extends RecordMapper<PostRecord, Post>
 */
class PostMapper extends RecordMapper
{
    /**
     * The AT Protocol record class this mapper handles.
     */
    public function recordClass(): string
    {
        return PostRecord::class;
    }

    /**
     * The Eloquent model class this mapper handles.
     */
    public function modelClass(): string
    {
        return Post::class;
    }

    /**
     * Which records this mapper accepts from the network.
     */
    public function accepts(): ?Acceptance
    {
        return Acceptance::connectedActors();
    }

    /**
     * Transform a record DTO into model attributes.
     */
    protected function recordToAttributes(Data $record): array
    {
        /** @var PostRecord $record */
        return [
            'content' => $record->text,
            'published_at' => $record->createdAt,
            'langs' => $record->langs,
            'facets' => $record->facets,
        ];
    }

    /**
     * Transform a model into record data for creating/updating.
     */
    protected function modelToRecordData(Model $model): array
    {
        /** @var Post $model */
        return [
            'text' => $model->content,
            'createdAt' => $model->published_at->toIso8601String(),
            'langs' => $model->langs ?? ['en'],
        ];
    }
}
```

## Required Methods

### `recordClass(): string`

Returns the fully qualified class name of the AT Protocol record DTO. This can be:

- A generated class from atp-schema (e.g., `SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post`)
- A custom class extending `SocialDept\AtpParity\Data\Record`

### `modelClass(): string`

Returns the fully qualified class name of the Eloquent model.

### `recordToAttributes(Data $record): array` and `modelToRecordData(Model $model): array`

Both are protected. Override them, or declare `fields()` instead and let the base class build both directions from the field map (see [Fields](fields.md)). A mapper that does neither throws a `LogicException` the first time either direction runs.

`recordToAttributes()` transforms an AT Protocol record into an array of Eloquent model attributes. This is used when:

- Creating a new model from a remote record
- Updating an existing model from a remote record

`modelToRecordData()` transforms an Eloquent model into an array suitable for creating an AT Protocol record. This is used when:

- Publishing a local model to the AT Protocol network
- Comparing local and remote state

### `accepts(): ?Acceptance`

Says which records the mapper accepts from the network. The base class returns `null`, and `shouldImport()` refuses every record when `accepts()` is `null`, so a mapper that does not override it imports nothing. `SocialDept\AtpParity\Acceptance\Acceptance` offers `anything()`, `none()`, `connectedActors()`, `knownActors()` and `when(callable)`, combined with `and()` and `or()`. `connectedActors()` and `knownActors()` need the `atp-parity.acceptance.is_connected_actor` and `atp-parity.acceptance.is_known_actor` config lookups, and accept nothing while those are unset.

## Inherited Methods

The abstract `RecordMapper` class provides these methods:

### `lexicon(): string`

Returns the lexicon NSID (e.g., `app.bsky.feed.post`). Taken from a `#[Lexicon('...')]` attribute on the mapper class (`SocialDept\AtpParity\Attributes\Lexicon`) when present, otherwise from the record class's `getLexicon()` method.

### `toModel(Data $record, array $meta = []): Model`

Creates a new (unsaved) model instance from a record DTO.

```php
$record = PostRecord::fromArray($data);
$model = $mapper->toModel($record, [
    'uri' => 'at://did:plc:xxx/app.bsky.feed.post/abc123',
    'cid' => 'bafyre...',
]);
```

### `toRecord(Model $model): Data`

Converts a model back to a record DTO.

```php
$record = $mapper->toRecord($post);
// Use $record->toArray() to get data for API calls
```

### `updateModel(Model $model, Data $record, array $meta = []): Model`

Updates an existing model with data from a record. Does not save the model.

```php
$mapper->updateModel($existingPost, $record, ['cid' => $newCid]);
$existingPost->save();
```

### `findByUri(string $uri): ?Model`

Finds a model by its AT Protocol URI.

```php
$post = $mapper->findByUri('at://did:plc:xxx/app.bsky.feed.post/abc123');
```

### `upsert(Data $record, array $meta = []): ?Model`

Creates or updates a model based on the URI, and saves it. This is the primary method used for syncing. It returns `null` without writing anything when `shouldImport()` refuses the record.

```php
$post = $mapper->upsert($record, [
    'uri' => $uri,
    'cid' => $cid,
]);
```

### `deleteByUri(string $uri): bool`

Deletes a model by its AT Protocol URI.

```php
$deleted = $mapper->deleteByUri('at://did:plc:xxx/app.bsky.feed.post/abc123');
```

## Meta Fields

The `$meta` array passed to `toModel`, `updateModel`, and `upsert` can contain:

| Key | Description |
|-----|-------------|
| `uri` | The AT Protocol URI (e.g., `at://did:plc:xxx/app.bsky.feed.post/abc123`) |
| `cid` | The content identifier hash |
| `rkey` | The record key. Stored only when an rkey column is configured |
| `did` | The DID of the repo the record came from |
| `backfill` | `true` when the record is a historical replay rather than a live commit |

The sync paths fill these in. `uri`, `cid` and `rkey` are written to your configured column names (default: `atp_uri`, `atp_cid`, and no rkey column). `toModel()` and `updateModel()` also set the synced-at column (default `atp_synced_at`) to the current time.

## Customizing Column Names

Override the column methods to use different database columns:

```php
class PostMapper extends RecordMapper
{
    protected function uriColumn(): string
    {
        return 'at_uri'; // Instead of default 'atp_uri'
    }

    protected function cidColumn(): string
    {
        return 'at_cid'; // Instead of default 'atp_cid'
    }

    protected function syncedAtColumn(): string
    {
        return 'at_synced_at'; // Instead of default 'atp_synced_at'
    }

    protected function rkeyColumn(): ?string
    {
        return 'rkey'; // Default null: the rkey is not stored
    }

    // ... other methods
}
```

Or configure globally in `config/atp-parity.php`:

```php
'columns' => [
    'uri' => 'at_uri',
    'cid' => 'at_cid',
    'synced_at' => 'at_synced_at',
    'rkey' => 'rkey',
],
```

## Registering Mappers

### Via Configuration

Add your mapper classes to `config/atp-parity.php`:

```php
return [
    'mappers' => [
        App\AtpMappers\PostMapper::class,
        App\AtpMappers\ProfileMapper::class,
        App\AtpMappers\LikeMapper::class,
    ],
];
```

### Programmatically

Register mappers at runtime via the `MapperRegistry`:

```php
use SocialDept\AtpParity\MapperRegistry;

$registry = app(MapperRegistry::class);
$registry->register(new PostMapper());
```

## Using the Registry

The `MapperRegistry` provides lookup methods:

```php
use SocialDept\AtpParity\MapperRegistry;

$registry = app(MapperRegistry::class);

// Find mapper by record class
$mapper = $registry->forRecord(PostRecord::class);

// Find the first mapper registered for a model class
$mapper = $registry->forModel(Post::class);

// Find every mapper registered for a model class
$mappers = $registry->forModelAll(Post::class);

// Find mapper by lexicon NSID
$mapper = $registry->forLexicon('app.bsky.feed.post');

// Check whether a lexicon has a mapper
$registry->hasLexicon('app.bsky.feed.post');

// Get all registered lexicons
$lexicons = $registry->lexicons();
// ['app.bsky.feed.post', 'app.bsky.actor.profile', ...]

// Get all registered mappers, one per lexicon
$mappers = $registry->all();
```

## SchemaMapper for Quick Setup

For simple mappings, use `SchemaMapper` instead of creating a full class:

```php
use SocialDept\AtpParity\Acceptance\Acceptance;
use SocialDept\AtpParity\Support\SchemaMapper;
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Like;

$mapper = new SchemaMapper(
    schemaClass: Like::class,
    modelClass: \App\Models\Like::class,
    toAttributes: fn(Like $like) => [
        'subject_uri' => $like->subject->uri,
        'subject_cid' => $like->subject->cid,
        'liked_at' => $like->createdAt,
    ],
    toRecordData: fn($model) => [
        'subject' => [
            'uri' => $model->subject_uri,
            'cid' => $model->subject_cid,
        ],
        'createdAt' => $model->liked_at->toIso8601String(),
    ],
    accepts: Acceptance::connectedActors(),
);

$registry->register($mapper);
```

`accepts` is optional and defaults to `null`, which accepts nothing.

## Handling Complex Records

### Embedded Objects

AT Protocol records often contain embedded objects. Handle them in your mapping:

```php
protected function recordToAttributes(Data $record): array
{
    /** @var PostRecord $record */
    $attributes = [
        'content' => $record->text,
        'published_at' => $record->createdAt,
    ];

    // Handle reply reference
    if ($record->reply) {
        $attributes['reply_to_uri'] = $record->reply->parent->uri;
        $attributes['thread_root_uri'] = $record->reply->root->uri;
    }

    // Handle embed
    if ($record->embed) {
        $attributes['embed_type'] = $record->embed->getType();
        $attributes['embed_data'] = $record->embed->toArray();
    }

    return $attributes;
}
```

### Facets (Rich Text)

Posts with mentions, links, and hashtags have facets:

```php
protected function recordToAttributes(Data $record): array
{
    /** @var PostRecord $record */
    return [
        'content' => $record->text,
        'facets' => $record->facets, // Store as JSON
        'published_at' => $record->createdAt,
    ];
}

protected function modelToRecordData(Model $model): array
{
    /** @var Post $model */
    return [
        'text' => $model->content,
        'facets' => $model->facets, // Restore from JSON
        'createdAt' => $model->published_at->toIso8601String(),
    ];
}
```

## Multiple Mappers per Lexicon

You can register multiple mappers for different model types:

```php
// Map posts to different models based on criteria
class UserPostMapper extends RecordMapper
{
    public function recordClass(): string
    {
        return PostRecord::class;
    }

    public function modelClass(): string
    {
        return UserPost::class;
    }

    // ... mapping logic for user's own posts
}

class FeedPostMapper extends RecordMapper
{
    public function recordClass(): string
    {
        return PostRecord::class;
    }

    public function modelClass(): string
    {
        return FeedPost::class;
    }

    // ... mapping logic for feed posts
}
```

Note: the registry keeps one mapper per record class and one per lexicon, so for both of these `forRecord()` and `forLexicon()` return the mapper registered last. `forModel()` returns the first mapper registered for that model, and `forModelAll()` returns all of them. Use explicit mapper instances when you need specific behavior.
