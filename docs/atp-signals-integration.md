# atp-signals Integration

Parity integrates with atp-signals to automatically sync firehose events to your Eloquent models in real-time. The `ParitySignal` class handles create, update, and delete operations for all registered mappers.

## ParitySignal

The `ParitySignal` is a pre-built signal that listens for commit events and syncs them to your database using your registered mappers.

### How It Works

1. ParitySignal listens for `commit` events on the firehose
2. It filters for collections that have registered mappers
3. For each matching event:
   - **Create/Update**: Upserts the record to your database
   - **Delete**: Removes the record from your database

### Setup

Register the signal in your atp-signals config:

```php
// config/signal.php
return [
    'signals' => [
        \SocialDept\AtpParity\Signals\ParitySignal::class,
    ],
];
```

Then start consuming:

```bash
php artisan signal:consume
```

That's it. Your models will automatically sync with the firehose.

## What Gets Synced

ParitySignal only syncs collections that have registered mappers:

```php
// config/parity.php
return [
    'mappers' => [
        App\AtpMappers\PostMapper::class,    // app.bsky.feed.post
        App\AtpMappers\LikeMapper::class,    // app.bsky.feed.like
        App\AtpMappers\FollowMapper::class,  // app.bsky.graph.follow
    ],
];
```

With this config, ParitySignal will sync posts, likes, and follows. All other collections are ignored.

## Event Flow

```
Firehose Event
      ↓
ParitySignal.handle()
      ↓
Check: Is collection registered?
      ↓
   Yes → Get mapper for collection
      ↓
Create DTO from event record
      ↓
Call mapper.upsert() or mapper.deleteByUri()
      ↓
Model saved to database
```

## Example: Syncing Posts

### 1. Create the Model

```php
// app/Models/Post.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\SyncsWithAtp;

class Post extends Model
{
    use SyncsWithAtp;

    protected $fillable = [
        'content',
        'author_did',
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

### 2. Create the Migration

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->text('content');
    $table->string('author_did');
    $table->timestamp('published_at');
    $table->string('atp_uri')->unique();
    $table->string('atp_cid');
    $table->timestamp('atp_synced_at')->nullable();
    $table->timestamps();
});
```

### 3. Create the Mapper

```php
// app/AtpMappers/PostMapper.php
namespace App\AtpMappers;

use App\Models\Post;
use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\RecordMapper;
use SocialDept\AtpSchema\Data\Data;
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post as PostRecord;

class PostMapper extends RecordMapper
{
    public function recordClass(): string
    {
        return PostRecord::class;
    }

    public function modelClass(): string
    {
        return Post::class;
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

### 4. Register Everything

```php
// config/parity.php
return [
    'mappers' => [
        App\AtpMappers\PostMapper::class,
    ],
];
```

```php
// config/signal.php
return [
    'signals' => [
        \SocialDept\AtpParity\Signals\ParitySignal::class,
    ],
];
```

### 5. Start Syncing

```bash
php artisan signal:consume
```

Every new post on the AT Protocol network will now be saved to your `posts` table.

## Filtering by User

To only sync records from specific users, create a custom signal:

```php
namespace App\Signals;

use SocialDept\AtpParity\Signals\ParitySignal;
use SocialDept\AtpSignals\Events\SignalEvent;

class FilteredParitySignal extends ParitySignal
{
    /**
     * DIDs to sync.
     */
    protected array $allowedDids = [
        'did:plc:abc123',
        'did:plc:def456',
    ];

    public function handle(SignalEvent $event): void
    {
        // Only process events from allowed DIDs
        if (!in_array($event->did, $this->allowedDids)) {
            return;
        }

        parent::handle($event);
    }
}
```

Register your custom signal instead:

```php
// config/signal.php
return [
    'signals' => [
        App\Signals\FilteredParitySignal::class,
    ],
];
```

## Filtering by Collection

To only sync specific collections (even if more mappers are registered):

```php
namespace App\Signals;

use SocialDept\AtpParity\Signals\ParitySignal;

class PostsOnlySignal extends ParitySignal
{
    public function collections(): ?array
    {
        // Only sync posts, ignore other registered mappers
        return ['app.bsky.feed.post'];
    }
}
```

## Custom Processing

Add custom logic before or after syncing:

```php
namespace App\Signals;

use SocialDept\AtpParity\Contracts\RecordMapper;
use SocialDept\AtpParity\Signals\ParitySignal;
use SocialDept\AtpSignals\Events\SignalEvent;

class CustomParitySignal extends ParitySignal
{
    protected function handleUpsert(SignalEvent $event, RecordMapper $mapper): void
    {
        // Pre-processing
        logger()->info('Syncing record', [
            'did' => $event->did,
            'collection' => $event->commit->collection,
            'rkey' => $event->commit->rkey,
        ]);

        // Call parent to do the actual sync
        parent::handleUpsert($event, $mapper);

        // Post-processing
        // e.g., dispatch a job, send notification, etc.
    }

    protected function handleDelete(SignalEvent $event, RecordMapper $mapper): void
    {
        logger()->info('Deleting record', [
            'uri' => $this->buildUri($event->did, $event->commit->collection, $event->commit->rkey),
        ]);

        parent::handleDelete($event, $mapper);
    }
}
```

## Queue Integration

For high-volume processing, enable queue mode:

```php
namespace App\Signals;

use SocialDept\AtpParity\Signals\ParitySignal;

class QueuedParitySignal extends ParitySignal
{
    public function shouldQueue(): bool
    {
        return true;
    }

    public function queue(): string
    {
        return 'parity-sync';
    }
}
```

Then run a dedicated queue worker:

```bash
php artisan queue:work --queue=parity-sync
```

## Multiple Signals

You can run ParitySignal alongside other signals:

```php
// config/signal.php
return [
    'signals' => [
        // Sync to database
        \SocialDept\AtpParity\Signals\ParitySignal::class,

        // Your custom analytics signal
        App\Signals\AnalyticsSignal::class,

        // Your moderation signal
        App\Signals\ModerationSignal::class,
    ],
];
```

## Handling High Volume

The AT Protocol firehose processes thousands of events per second. For production:

### 1. Use Jetstream Mode

Jetstream filters server-side, reducing bandwidth:

```php
// config/signal.php
return [
    'mode' => 'jetstream', // More efficient than firehose

    'jetstream' => [
        'collections' => [
            'app.bsky.feed.post',
            'app.bsky.feed.like',
        ],
    ],
];
```

### 2. Enable Queues

Process events asynchronously:

```php
class QueuedParitySignal extends ParitySignal
{
    public function shouldQueue(): bool
    {
        return true;
    }
}
```

### 3. Use Database Transactions

Batch inserts for better performance:

```php
namespace App\Signals;

use Illuminate\Support\Facades\DB;
use SocialDept\AtpParity\Signals\ParitySignal;
use SocialDept\AtpSignals\Events\SignalEvent;

class BatchedParitySignal extends ParitySignal
{
    protected array $buffer = [];
    protected int $batchSize = 100;

    public function handle(SignalEvent $event): void
    {
        $this->buffer[] = $event;

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    protected function flush(): void
    {
        DB::transaction(function () {
            foreach ($this->buffer as $event) {
                parent::handle($event);
            }
        });

        $this->buffer = [];
    }
}
```

### 4. Monitor Performance

Log sync statistics:

```php
namespace App\Signals;

use SocialDept\AtpParity\Signals\ParitySignal;
use SocialDept\AtpSignals\Events\SignalEvent;

class MonitoredParitySignal extends ParitySignal
{
    protected int $processed = 0;
    protected float $startTime;

    public function handle(SignalEvent $event): void
    {
        $this->startTime ??= microtime(true);

        parent::handle($event);

        $this->processed++;

        if ($this->processed % 1000 === 0) {
            $elapsed = microtime(true) - $this->startTime;
            $rate = $this->processed / $elapsed;

            logger()->info("Parity sync stats", [
                'processed' => $this->processed,
                'elapsed' => round($elapsed, 2),
                'rate' => round($rate, 2) . '/sec',
            ]);
        }
    }
}
```

## Cursor Management

atp-signals handles cursor persistence automatically. If the consumer restarts, it resumes from where it left off.

To reset and start fresh:

```bash
php artisan signal:consume --reset
```

## Testing

Test your sync setup without connecting to the firehose:

```php
use App\AtpMappers\PostMapper;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post;

// Create a test record
$record = Post::fromArray([
    'text' => 'Test post content',
    'createdAt' => now()->toIso8601String(),
]);

// Get the mapper
$registry = app(MapperRegistry::class);
$mapper = $registry->forLexicon('app.bsky.feed.post');

// Simulate a sync
$model = $mapper->upsert($record, [
    'uri' => 'at://did:plc:test/app.bsky.feed.post/test123',
    'cid' => 'bafyretest...',
]);

// Assert
$this->assertDatabaseHas('posts', [
    'content' => 'Test post content',
    'atp_uri' => 'at://did:plc:test/app.bsky.feed.post/test123',
]);
```
