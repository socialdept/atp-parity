# Introduction

**Parity** maps AT Protocol records to Laravel Eloquent models and back. Think of it as Laravel's model casts, but for AT Protocol records:

- **Mapping in both directions:** one mapper turns a record into a model and a model into a record.
- **Declared fields:** state each field once, with defaults, codecs, enums and blobs, and the package knows which columns the record contains.
- **Ingest boundaries:** every mapper says which repos it accepts records from, and accepts nothing until it does.
- **Firehose sync:** apply network events to your database through atp-signals, or records from any other transport.
- **Importing:** backfill the records a repo already holds.
- **Automatic syncing:** write a model to its author's repo when it is created, updated or deleted.
- **Blobs:** download, upload and serve images and videos, on the filesystem or with Spatie MediaLibrary.
- **Typed records:** use atp-schema generated DTOs directly, or write your own records.

It builds on [atp-schema](https://github.com/socialdept/atp-schema) for records, [atp-client](https://github.com/socialdept/atp-client) for fetching and writing, and [atp-signals](https://github.com/socialdept/atp-signals) for the firehose.

## A first look

A mapper declares how a record and a model correspond, and which repos it accepts records from:

```php
use SocialDept\AtpParity\Acceptance\Acceptance;
use SocialDept\AtpParity\Fields\Field;
use SocialDept\AtpParity\RecordMapper;

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

    public function fields(): array
    {
        return [
            'text' => 'content',
            'createdAt' => Field::for('published_at'),
        ];
    }

    public function accepts(): ?Acceptance
    {
        return Acceptance::connectedActors();
    }
}
```

A model with `AutoSyncsWithAtp` writes itself to its author's repo. An update only syncs when it changed a column the record contains:

```php
$post = Post::create(['content' => 'Hello!']);   // Syncs to ATP
$post->update(['content' => 'Updated']);         // Updates the record
$post->update(['view_count' => 41]);             // No write: not in the record
$post->delete();                                 // Removes from ATP
```

Records that already exist are fetched or imported into the same models:

```php
use SocialDept\AtpParity\Support\RecordHelper;

$post = app(RecordHelper::class)->sync('at://did:plc:xxx/app.bsky.feed.post/abc123');
```

## Documentation

Start with [Installation](getting-started/installation.md), then the [Quick Start](getting-started/quick-start.md) for a first mapper and model. Coming from an earlier version? See [Upgrading](../UPGRADING.md). The docs are grouped by area, and each group has an index of its own:

- **[Getting started](getting-started/README.md)**: [Installation](getting-started/installation.md), [Quick Start](getting-started/quick-start.md)
- **[Mapping records](mapping/README.md)**: [Record Mappers](mapping/mappers.md), [Declared Fields](mapping/fields.md), [atp-schema Integration](mapping/atp-schema.md), [Testing Mappers](mapping/testing.md)
- **[Models](models/README.md)**: [Model Traits](models/traits.md), [Automatic Syncing](models/auto-sync.md), [Blob Handling](models/blobs.md)
- **[Syncing from the network](network/README.md)**: [atp-signals Integration](network/atp-signals.md), [Importing Records](network/importing.md), [atp-client Integration](network/atp-client.md)
