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

## Pending Syncs (Retry After Re-Authentication)

When auto-sync fails due to an expired or invalid OAuth session, the sync operation can be captured and retried after the user re-authenticates. This is opt-in and disabled by default.

### Enabling Pending Syncs

```env
PARITY_PENDING_SYNCS_ENABLED=true
```

### Configuration

```php
// config/parity.php
'pending_syncs' => [
    // Enable the feature
    'enabled' => env('PARITY_PENDING_SYNCS_ENABLED', false),

    // Storage driver: 'cache' (default) or 'database'
    // Use 'database' for durability with queue workers
    'storage' => env('PARITY_PENDING_SYNCS_STORAGE', 'cache'),

    // How long to keep pending syncs (seconds)
    'ttl' => env('PARITY_PENDING_SYNCS_TTL', 3600), // 1 hour

    // Max retry attempts before discarding
    'max_attempts' => env('PARITY_PENDING_SYNCS_MAX_ATTEMPTS', 3),

    // Auto-retry when user re-authenticates
    'auto_retry' => env('PARITY_PENDING_SYNCS_AUTO_RETRY', false),
],
```

### Storage Options

**Cache (default):** Simple, no migration needed. Good for single-server setups.

**Database:** Durable, survives restarts. Required if using queue workers on separate processes. Requires publishing and running the migration:

```bash
php artisan vendor:publish --tag=parity-migrations-pending-syncs
php artisan migrate
```

### Manual Retry in OAuth Callback

```php
use SocialDept\AtpParity\Facades\Parity;

public function handleOAuthCallback(Request $request)
{
    $session = Atp::oauth()->handleCallback($request);

    // Retry any pending syncs for this user
    if (Parity::hasPendingSyncs($session->did)) {
        $result = Parity::retryPendingSyncs($session->did);

        if ($result->hasFailures()) {
            Log::warning('Some syncs failed', ['errors' => $result->errors]);
        }
    }

    return redirect()->route('home');
}
```

### Auto-Retry on Re-Authentication

Enable automatic retry when the `SessionAuthenticated` event fires:

```env
PARITY_PENDING_SYNCS_AUTO_RETRY=true
```

This registers a listener that automatically retries pending syncs whenever a user successfully authenticates via OAuth.

### Events

Listen to pending sync events for observability:

```php
use SocialDept\AtpParity\Events\PendingSyncCaptured;
use SocialDept\AtpParity\Events\PendingSyncRetried;
use SocialDept\AtpParity\Events\PendingSyncFailed;

// When a sync is captured for later retry
Event::listen(PendingSyncCaptured::class, function ($event) {
    Log::info('Sync pending', [
        'did' => $event->pendingSync->did,
        'model' => $event->pendingSync->modelClass,
    ]);
});

// When a pending sync is retried
Event::listen(PendingSyncRetried::class, function ($event) {
    Log::info('Sync retried', [
        'success' => $event->success,
        'id' => $event->pendingSync->id,
    ]);
});

// When a retry fails with a non-auth exception
Event::listen(PendingSyncFailed::class, function ($event) {
    Log::error('Sync failed', [
        'error' => $event->exception->getMessage(),
    ]);
});
```

### Checking Pending Syncs

```php
use SocialDept\AtpParity\Facades\Parity;

// Check if user has pending syncs
Parity::hasPendingSyncs($did);

// Count pending syncs
Parity::countPendingSyncs($did);

// Get all pending syncs for a DID
$pending = app(PendingSyncManager::class)->forDid($did);
```

### How It Works

1. Auto-sync operation fails with `AuthenticationException` or `OAuthSessionInvalidException`
2. The pending sync is captured (model class, ID, operation type)
3. The auth exception is re-thrown (so your app can redirect to OAuth)
4. User re-authenticates via OAuth
5. Pending syncs are retried (manually or automatically)
6. Successful syncs are removed; failed syncs remain for next attempt

### Edge Cases

- **Deleted models:** If a model is deleted before retry, the pending sync is considered "handled" and removed
- **Max attempts:** After exceeding `max_attempts`, the pending sync is discarded
- **TTL expiry:** Expired pending syncs are cleaned up (call `Parity::cleanupPendingSyncs()` periodically or use database storage which handles this automatically)
- **Auth still invalid:** If retry fails with another auth exception, it bubbles up for another re-auth cycle

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
