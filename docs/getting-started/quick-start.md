# Quick Start

Part of the [atp-parity docs](../introduction.md#documentation). Three steps from an installed package to a model that converts to and from AT Protocol records.

## 1. Create a mapper

A mapper defines how a record maps to your model. `php artisan make:atp-mapper PostMapper --model=Post --record=Post` writes a starting point to `app/AtpMappers` (the `generators.mapper_path` config key). The generated class overrides `recordToAttributes()` and `modelToRecordData()` and has no `accepts()` yet, so add one before it imports anything. A mapper with declared fields looks like this:

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

`recordClass()` returns an atp-schema generated DTO or a [custom record](../mapping/atp-schema.md#creating-custom-records). See [Declared Fields](../mapping/fields.md) for everything a field can do.

`accepts()` is not optional. A mapper is an ingest boundary, and one that has not said what it allows imports nothing. `Acceptance::connectedActors()` needs the `acceptance.is_connected_actor` lookup in the config, covered in [Installation](installation.md#configuration).

## 2. Register the mapper

```php
// config/atp-parity.php
return [
    'mappers' => [
        App\AtpMappers\PostMapper::class,
    ],
];
```

## 3. Add the trait to your model

```php
use SocialDept\AtpParity\Concerns\HasAtpRecord;

class Post extends Model
{
    use HasAtpRecord;
}
```

Your model can now convert to and from AT Protocol records and be queried by URI:

```php
$post->getAtpUri();        // at://did:plc:xxx/app.bsky.feed.post/rkey
$post->getAtpDid();        // did:plc:xxx (extracted from URI)
$post->hasAtpRecord();     // true if synced

$record = $post->toAtpRecord();

Post::withAtpRecord()->get();      // Only synced posts
Post::whereAtpUri($uri)->first();  // Find by URI
```

## Next steps

- [Model Traits](../models/traits.md) for every method the traits add.
- [Automatic Syncing](../models/auto-sync.md) to write models to their author's repo on save.
- [atp-signals Integration](../network/atp-signals.md) to sync records from the firehose.
- [Importing Records](../network/importing.md) for records written before you started listening.
