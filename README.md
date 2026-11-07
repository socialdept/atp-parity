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

This package maps AT Protocol records to Laravel Eloquent models and back. A mapper declares how a record and a model correspond, and the package uses it to apply firehose events and imports to your database and to write your models to their author's repo. To learn all about it, head over to [the documentation](https://socialde.pt/docs/atp-parity).

Here are a few short examples of what you can do. Declare a mapper once for both directions:

```php
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

Query models by their record:

```php
Post::withAtpRecord()->get();
Post::whereAtpUri('at://did:plc:xxx/app.bsky.feed.post/abc123')->first();
```

Write a model to its author's repo whenever it changes, with the `AutoSyncsWithAtp` trait:

```php
$post = Post::create(['content' => 'Hello!']);   // Syncs to ATP
$post->update(['view_count' => 41]);             // No write: not in the record
```

Fetch a single record into a model:

```php
$post = app(RecordHelper::class)->sync('at://did:plc:xxx/app.bsky.feed.post/abc123');
```

Or import everything a repo already holds:

```bash
php artisan parity:import did:plc:z72i7hdynmk6r22z27h6tvur
```

## Documentation

You'll find the documentation on [https://socialde.pt/docs/atp-parity](https://socialde.pt/docs/atp-parity).

Find yourself stuck using the package? Found a bug? Do you have general questions or suggestions for improving it? Feel free to [create an issue on GitHub](https://github.com/socialdept/atp-parity/issues).

## Testing

```bash
vendor/bin/phpunit
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
