# Automatic Syncing

Parity provides automatic synchronization of Eloquent models with AT Protocol records through the `AutoSyncsWithAtp` trait. When enabled, models are automatically synced to AT Protocol when created, updated, or deleted.

## Quick Start

### 1. Add the Trait to Your Model

```php
use SocialDept\AtpParity\Concerns\AutoSyncsWithAtp;

class Post extends Model
{
    use AutoSyncsWithAtp;

    // Specify which DID to sync as
    public function syncAsDid(): ?string
    {
        return $this->user->did;
    }
}
```

### 2. Register a Mapper

Auto-sync requires a mapper to know how to convert your model to an AT Protocol record:

```php
// config/parity.php
'mappers' => [
    App\AtpMappers\PostMapper::class,
],
```

### 3. That's It

When you create, update, or delete the model, it will automatically sync:

```php
// Creates model AND syncs to AT Protocol
$post = Post::create(['content' => 'Hello world!']);

// Updates model AND syncs changes to AT Protocol
$post->update(['content' => 'Updated content']);

// Deletes model AND removes from AT Protocol
$post->delete();
```

## Configuration

### syncAsDid()

Override this method to specify which DID the record should be synced as. The DID must have an authenticated session available.

```php
public function syncAsDid(): ?string
{
    return $this->user->did;
}
```

The default implementation checks:
1. A `did` column on the model
2. A `user` relationship with a `did` property
3. An `author` relationship with a `did` property

### shouldAutoSync()

Override this method to control when syncing occurs. By default, it always returns `true`.

```php
public function shouldAutoSync(): bool
{
    // Only sync when status is 'published'
    return $this->status === 'published';
}
```

This method is checked on both `created` and `updated` events.

### shouldAutoUnsync()

Override this method to control when records are removed from AT Protocol on delete. By default, it returns `true`.

```php
public function shouldAutoUnsync(): bool
{
    // Keep the AT Protocol record even after local deletion
    return false;
}
```

## Events

The following events are dispatched during sync operations:

### RecordPublished

Dispatched when a record is created or updated on AT Protocol.

```php
use SocialDept\AtpParity\Events\RecordPublished;

Event::listen(RecordPublished::class, function (RecordPublished $event) {
    Log::info('Record synced', [
        'model' => get_class($event->model),
        'uri' => $event->uri,
        'cid' => $event->cid,
    ]);
});
```

### RecordUnpublished

Dispatched when a record is deleted from AT Protocol.

```php
use SocialDept\AtpParity\Events\RecordUnpublished;

Event::listen(RecordUnpublished::class, function (RecordUnpublished $event) {
    Log::info('Record removed', [
        'model' => get_class($event->model),
        'uri' => $event->uri,
    ]);
});
```

## Examples

### Basic Usage

```php
use SocialDept\AtpParity\Concerns\AutoSyncsWithAtp;

class Post extends Model
{
    use AutoSyncsWithAtp;

    protected $fillable = ['content', 'user_id', 'atp_uri', 'atp_cid'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function syncAsDid(): ?string
    {
        return $this->user->did;
    }
}
```

### Conditional Sync Based on Status

```php
class Article extends Model
{
    use AutoSyncsWithAtp;

    public function shouldAutoSync(): bool
    {
        // Only sync published articles
        return $this->status === 'published';
    }

    public function syncAsDid(): ?string
    {
        return $this->author->did;
    }
}
```

### Sync with Soft Deletes

When using soft deletes, `shouldAutoUnsync()` is called on the soft delete. If you want to keep the AT Protocol record until force delete:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use AutoSyncsWithAtp, SoftDeletes;

    public function shouldAutoUnsync(): bool
    {
        // Only unsync on force delete
        return $this->isForceDeleting();
    }
}
```

### Multi-Tenant Sync

For multi-tenant applications where different users own different records:

```php
class Publication extends Model
{
    use AutoSyncsWithAtp;

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function syncAsDid(): ?string
    {
        // Each publication syncs as its owner
        return $this->owner->did;
    }

    public function shouldAutoSync(): bool
    {
        // Only sync if owner has connected their AT Protocol account
        return $this->owner?->did !== null;
    }
}
```

## Trait Hierarchy

`AutoSyncsWithAtp` uses `PublishesRecords` which uses `HasAtpRecord`:

```
AutoSyncsWithAtp
  └── PublishesRecords
        └── HasAtpRecord
```

This means your model automatically has access to all methods from these traits:

```php
$post->hasAtpRecord();      // Check if synced
$post->getAtpUri();         // Get AT Protocol URI
$post->getAtpCid();         // Get content ID
$post->toAtpRecord();       // Convert to Record DTO
$post->publish();           // Manual publish
$post->republish();         // Manual update
$post->unpublish();         // Manual delete
```

## Error Handling

By default, sync errors are caught and logged but don't prevent model operations. The model will be saved/updated/deleted locally even if the AT Protocol sync fails.

To handle sync errors explicitly, listen to the events or check the `atp_uri` column after save:

```php
$post = Post::create(['content' => 'Hello']);

if (!$post->hasAtpRecord()) {
    // Sync failed - handle accordingly
    Log::warning('Failed to sync post', ['id' => $post->id]);
}
```

## Manual Sync

You can also trigger sync manually using methods from `PublishesRecords`:

```php
// Manual publish (uses syncAsDid())
$post->publish();

// Publish as specific DID
$post->publishAs('did:plc:xxx');

// Update existing record
$post->republish();

// Remove from AT Protocol
$post->unpublish();
```
