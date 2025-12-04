# atp-schema Integration

Parity is built on top of atp-schema, using its `Data` base class for all record DTOs. This provides type safety, validation, and compatibility with the AT Protocol ecosystem.

## How It Works

The `SocialDept\AtpParity\Data\Record` class extends `SocialDept\AtpSchema\Data\Data`:

```php
namespace SocialDept\AtpParity\Data;

use SocialDept\AtpClient\Contracts\Recordable;
use SocialDept\AtpSchema\Data\Data;

abstract class Record extends Data implements Recordable
{
    public function getType(): string
    {
        return static::getLexicon();
    }
}
```

This means all Parity records inherit:

- `getLexicon()` - Returns the lexicon NSID
- `fromArray()` - Creates instance from array data
- `toArray()` - Converts to array
- `toRecord()` - Converts to record format for API calls
- Type validation and casting

## Using Generated Schema Classes

atp-schema generates PHP classes for all AT Protocol lexicons. Use them directly with Parity:

```php
use SocialDept\AtpParity\Support\SchemaMapper;
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post;
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Like;
use SocialDept\AtpSchema\Generated\App\Bsky\Graph\Follow;

// Post mapper
$postMapper = new SchemaMapper(
    schemaClass: Post::class,
    modelClass: \App\Models\Post::class,
    toAttributes: fn(Post $post) => [
        'content' => $post->text,
        'published_at' => $post->createdAt,
        'langs' => $post->langs,
        'reply_parent' => $post->reply?->parent->uri,
        'reply_root' => $post->reply?->root->uri,
    ],
    toRecordData: fn($model) => [
        'text' => $model->content,
        'createdAt' => $model->published_at->toIso8601String(),
        'langs' => $model->langs ?? ['en'],
    ],
);

// Like mapper
$likeMapper = new SchemaMapper(
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
);

// Follow mapper
$followMapper = new SchemaMapper(
    schemaClass: Follow::class,
    modelClass: \App\Models\Follow::class,
    toAttributes: fn(Follow $follow) => [
        'subject_did' => $follow->subject,
        'followed_at' => $follow->createdAt,
    ],
    toRecordData: fn($model) => [
        'subject' => $model->subject_did,
        'createdAt' => $model->followed_at->toIso8601String(),
    ],
);
```

## Creating Custom Records

For custom lexicons or when you need more control, extend the `Record` class:

```php
<?php

namespace App\AtpRecords;

use Carbon\Carbon;
use SocialDept\AtpParity\Data\Record;

class CustomPost extends Record
{
    public function __construct(
        public readonly string $text,
        public readonly Carbon $createdAt,
        public readonly ?array $facets = null,
        public readonly ?array $embed = null,
        public readonly ?array $langs = null,
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
            embed: $data['embed'] ?? null,
            langs: $data['langs'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            '$type' => static::getLexicon(),
            'text' => $this->text,
            'createdAt' => $this->createdAt->toIso8601String(),
            'facets' => $this->facets,
            'embed' => $this->embed,
            'langs' => $this->langs,
        ], fn($v) => $v !== null);
    }
}
```

## Custom Lexicons (AppView)

Building a custom AT Protocol application? Define your own lexicons:

```php
<?php

namespace App\AtpRecords;

use Carbon\Carbon;
use SocialDept\AtpParity\Data\Record;

class Article extends Record
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly Carbon $publishedAt,
        public readonly ?array $tags = null,
        public readonly ?string $coverImage = null,
    ) {}

    public static function getLexicon(): string
    {
        return 'com.myapp.blog.article'; // Your custom NSID
    }

    public static function fromArray(array $data): static
    {
        return new static(
            title: $data['title'],
            body: $data['body'],
            publishedAt: Carbon::parse($data['publishedAt']),
            tags: $data['tags'] ?? null,
            coverImage: $data['coverImage'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            '$type' => static::getLexicon(),
            'title' => $this->title,
            'body' => $this->body,
            'publishedAt' => $this->publishedAt->toIso8601String(),
            'tags' => $this->tags,
            'coverImage' => $this->coverImage,
        ], fn($v) => $v !== null);
    }
}
```

## Working with Embedded Types

atp-schema generates classes for embedded types. Use them in your mappings:

```php
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post;
use SocialDept\AtpSchema\Generated\App\Bsky\Embed\Images;
use SocialDept\AtpSchema\Generated\App\Bsky\Embed\External;
use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;

$mapper = new SchemaMapper(
    schemaClass: Post::class,
    modelClass: \App\Models\Post::class,
    toAttributes: fn(Post $post) => [
        'content' => $post->text,
        'published_at' => $post->createdAt,
        'has_images' => $post->embed instanceof Images,
        'has_link' => $post->embed instanceof External,
        'embed_data' => $post->embed?->toArray(),
    ],
    toRecordData: fn($model) => [
        'text' => $model->content,
        'createdAt' => $model->published_at->toIso8601String(),
    ],
);
```

## Handling Union Types

AT Protocol uses union types for fields like `embed`. atp-schema handles these via discriminated unions:

```php
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post;
use SocialDept\AtpSchema\Generated\App\Bsky\Embed\Images;
use SocialDept\AtpSchema\Generated\App\Bsky\Embed\External;
use SocialDept\AtpSchema\Generated\App\Bsky\Embed\Record;
use SocialDept\AtpSchema\Generated\App\Bsky\Embed\RecordWithMedia;

$toAttributes = function(Post $post): array {
    $attributes = [
        'content' => $post->text,
        'published_at' => $post->createdAt,
    ];

    // Handle embed union type
    if ($post->embed) {
        match (true) {
            $post->embed instanceof Images => $attributes['embed_type'] = 'images',
            $post->embed instanceof External => $attributes['embed_type'] = 'external',
            $post->embed instanceof Record => $attributes['embed_type'] = 'quote',
            $post->embed instanceof RecordWithMedia => $attributes['embed_type'] = 'quote_media',
            default => $attributes['embed_type'] = 'unknown',
        };
        $attributes['embed_data'] = $post->embed->toArray();
    }

    return $attributes;
};
```

## Reply Threading

Posts can be replies to other posts:

```php
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post;

$toAttributes = function(Post $post): array {
    $attributes = [
        'content' => $post->text,
        'published_at' => $post->createdAt,
        'is_reply' => $post->reply !== null,
    ];

    if ($post->reply) {
        // Parent is the immediate post being replied to
        $attributes['reply_parent_uri'] = $post->reply->parent->uri;
        $attributes['reply_parent_cid'] = $post->reply->parent->cid;

        // Root is the top of the thread
        $attributes['reply_root_uri'] = $post->reply->root->uri;
        $attributes['reply_root_cid'] = $post->reply->root->cid;
    }

    return $attributes;
};
```

## Facets (Rich Text)

Posts with mentions, links, and hashtags use facets:

```php
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post;
use SocialDept\AtpSchema\Generated\App\Bsky\Richtext\Facet;

$toAttributes = function(Post $post): array {
    $attributes = [
        'content' => $post->text,
        'published_at' => $post->createdAt,
    ];

    // Extract mentions, links, and tags from facets
    $mentions = [];
    $links = [];
    $tags = [];

    foreach ($post->facets ?? [] as $facet) {
        foreach ($facet->features as $feature) {
            $type = $feature->getType();
            match ($type) {
                'app.bsky.richtext.facet#mention' => $mentions[] = $feature->did,
                'app.bsky.richtext.facet#link' => $links[] = $feature->uri,
                'app.bsky.richtext.facet#tag' => $tags[] = $feature->tag,
                default => null,
            };
        }
    }

    $attributes['mentions'] = $mentions;
    $attributes['links'] = $links;
    $attributes['tags'] = $tags;
    $attributes['facets'] = $post->facets; // Store raw for reconstruction

    return $attributes;
};
```

## Type Safety Benefits

Using atp-schema classes provides:

1. **IDE Autocompletion** - Full property and method suggestions
2. **Type Checking** - Static analysis catches errors
3. **Validation** - Data is validated on construction
4. **Documentation** - Generated classes include docblocks

```php
// IDE knows $post->text is string, $post->createdAt is string, etc.
$toAttributes = function(Post $post): array {
    return [
        'content' => $post->text,           // string
        'published_at' => $post->createdAt, // string (ISO 8601)
        'langs' => $post->langs,            // ?array
        'facets' => $post->facets,          // ?array
    ];
};
```

## Regenerating Schema Classes

When the AT Protocol schema updates, regenerate the classes:

```bash
# In the atp-schema package
php artisan atp:generate
```

Your mappers will automatically work with the updated types.
