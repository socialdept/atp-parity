# Importing Records

Parity includes a comprehensive import system that enables you to sync historical AT Protocol data to your Eloquent models. This complements the real-time sync provided by [ParitySignal](atp-signals-integration.md).

## The Cold Start Problem

When you start consuming the AT Protocol firehose with ParitySignal, you only receive events from that point forward. Any records created before you started listening are not captured.

Importing solves this "cold start" problem by fetching existing records from user repositories via the `com.atproto.repo.listRecords` API.

## Quick Start

### 1. Run the Migration

Publish and run the migration to create the import state tracking table:

```bash
php artisan vendor:publish --tag=parity-migrations
php artisan migrate
```

### 2. Import a User

```bash
# Import all registered collections for a user
php artisan parity:import did:plc:z72i7hdynmk6r22z27h6tvur

# Import a specific collection
php artisan parity:import did:plc:z72i7hdynmk6r22z27h6tvur --collection=app.bsky.feed.post

# Show progress
php artisan parity:import did:plc:z72i7hdynmk6r22z27h6tvur --progress
```

### 3. Check Status

```bash
# Show all import status
php artisan parity:import-status

# Show status for a specific user
php artisan parity:import-status did:plc:z72i7hdynmk6r22z27h6tvur

# Show only incomplete imports
php artisan parity:import-status --pending
```

## Programmatic Usage

### ImportService

The `ImportService` is the main orchestration class:

```php
use SocialDept\AtpParity\Import\ImportService;

$service = app(ImportService::class);

// Import all registered collections for a user
$result = $service->importUser('did:plc:z72i7hdynmk6r22z27h6tvur');

echo "Synced {$result->recordsSynced} records";

// Import a specific collection
$result = $service->importUserCollection(
    'did:plc:z72i7hdynmk6r22z27h6tvur',
    'app.bsky.feed.post'
);

// With progress callback
$result = $service->importUser('did:plc:z72i7hdynmk6r22z27h6tvur', null, function ($progress) {
    echo "Synced {$progress->recordsSynced} records from {$progress->collection}\n";
});
```

### ImportResult

The `ImportResult` value object provides information about the import operation:

```php
$result = $service->importUser($did);

$result->recordsSynced;   // Number of records successfully synced
$result->recordsSkipped;  // Number of records skipped
$result->recordsFailed;   // Number of records that failed to sync
$result->completed;       // Whether the import completed fully
$result->cursor;          // Cursor for resuming (if incomplete)
$result->error;           // Error message (if failed)

$result->isSuccess();     // True if completed without errors
$result->isPartial();     // True if some records were synced before failure
$result->isFailed();      // True if an error occurred
```

### Checking Status

```php
// Check if a collection has been imported
if ($service->isImported($did, 'app.bsky.feed.post')) {
    echo "Already imported!";
}

// Get detailed status
$state = $service->getStatus($did, 'app.bsky.feed.post');

if ($state) {
    echo "Status: {$state->status}";
    echo "Records synced: {$state->records_synced}";
}

// Get all statuses for a user
$states = $service->getStatusForUser($did);
```

### Resuming Interrupted Imports

If an import is interrupted (network error, timeout, etc.), you can resume it:

```php
// Resume a specific import
$state = $service->getStatus($did, $collection);
if ($state && $state->canResume()) {
    $result = $service->resume($state);
}

// Resume all interrupted imports
$results = $service->resumeAll();
```

### Resetting Import State

To re-import a user or collection:

```php
// Reset a specific collection
$service->reset($did, 'app.bsky.feed.post');

// Reset all collections for a user
$service->resetUser($did);
```

## Queue Integration

For large-scale importing, use the queue system:

### Command Line

```bash
# Queue an import job instead of running synchronously
php artisan parity:import did:plc:z72i7hdynmk6r22z27h6tvur --queue

# Queue imports for a list of DIDs
php artisan parity:import --file=dids.txt --queue
```

### Programmatic

```php
use SocialDept\AtpParity\Jobs\ImportUserJob;

// Dispatch a single user import
ImportUserJob::dispatch('did:plc:z72i7hdynmk6r22z27h6tvur');

// Dispatch for a specific collection
ImportUserJob::dispatch('did:plc:z72i7hdynmk6r22z27h6tvur', 'app.bsky.feed.post');
```

## Events

Parity dispatches events during importing that you can listen to:

### ImportStarted

Fired when an import operation begins:

```php
use SocialDept\AtpParity\Events\ImportStarted;

Event::listen(ImportStarted::class, function (ImportStarted $event) {
    Log::info("Starting import", [
        'did' => $event->did,
        'collection' => $event->collection,
    ]);
});
```

### ImportProgress

Fired after each page of records is processed:

```php
use SocialDept\AtpParity\Events\ImportProgress;

Event::listen(ImportProgress::class, function (ImportProgress $event) {
    Log::info("Import progress", [
        'did' => $event->did,
        'collection' => $event->collection,
        'records_synced' => $event->recordsSynced,
    ]);
});
```

### ImportCompleted

Fired when an import operation completes successfully:

```php
use SocialDept\AtpParity\Events\ImportCompleted;

Event::listen(ImportCompleted::class, function (ImportCompleted $event) {
    $result = $event->result;

    Log::info("Import completed", [
        'did' => $result->did,
        'collection' => $result->collection,
        'records_synced' => $result->recordsSynced,
    ]);
});
```

### ImportFailed

Fired when an import operation fails:

```php
use SocialDept\AtpParity\Events\ImportFailed;

Event::listen(ImportFailed::class, function (ImportFailed $event) {
    Log::error("Import failed", [
        'did' => $event->did,
        'collection' => $event->collection,
        'error' => $event->error,
    ]);
});
```

## Configuration

Configure importing in `config/parity.php`:

```php
'import' => [
    // Records per page when listing from PDS (max 100)
    'page_size' => 100,

    // Delay between pages in milliseconds (rate limiting)
    'page_delay' => 100,

    // Queue name for import jobs
    'queue' => 'parity-import',

    // Database table for storing import state
    'state_table' => 'parity_import_states',
],
```

## Batch Importing from File

Create a file with DIDs (one per line):

```text
did:plc:z72i7hdynmk6r22z27h6tvur
did:plc:ewvi7nxzyoun6zhxrhs64oiz
did:plc:ragtjsm2j2vknwkz3zp4oxrd
```

Then run:

```bash
# Synchronous (one at a time)
php artisan parity:import --file=dids.txt --progress

# Queued (parallel via workers)
php artisan parity:import --file=dids.txt --queue
```

## Coordinating with ParitySignal

For a complete sync solution, combine importing with real-time firehose sync:

1. **Start the firehose consumer** - Begin receiving live events
2. **Import historical data** - Fetch existing records
3. **Continue firehose sync** - New events are handled automatically

This ensures no gaps in your data. Records that arrive via firehose while importing will be properly deduplicated by the mapper's `upsert()` method (which uses the AT Protocol URI as the unique key).

```php
// Example: Import a user then subscribe to their updates
$service->importUser($did);

// The firehose consumer (ParitySignal) handles updates automatically
// as long as it's running with signal:consume
```

## Best Practices

### Rate Limiting

The `page_delay` config option helps prevent overwhelming PDS servers. For bulk importing, consider:

- Using queued jobs to spread load over time
- Increasing the delay between pages
- Running during off-peak hours

### Error Handling

Imports can fail due to:
- Network errors
- PDS rate limiting
- Invalid records

The system automatically tracks progress via cursor, allowing you to resume failed imports:

```bash
# Check for failed imports
php artisan parity:import-status --failed

# Resume all failed/interrupted imports
php artisan parity:import --resume
```

### Monitoring

Use the events to build monitoring:

```php
// Track import metrics
Event::listen(ImportCompleted::class, function (ImportCompleted $event) {
    Metrics::increment('parity.import.completed');
    Metrics::gauge('parity.import.records', $event->result->recordsSynced);
});

Event::listen(ImportFailed::class, function (ImportFailed $event) {
    Metrics::increment('parity.import.failed');
    Alert::send("Import failed for {$event->did}: {$event->error}");
});
```

## Database Schema

The import state table stores progress:

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| did | string | The DID being imported |
| collection | string | The collection NSID |
| status | string | pending, in_progress, completed, failed |
| cursor | string | Pagination cursor for resuming |
| records_synced | int | Count of successfully synced records |
| records_skipped | int | Count of skipped records |
| records_failed | int | Count of failed records |
| started_at | timestamp | When import started |
| completed_at | timestamp | When import completed |
| error | text | Error message if failed |
| created_at | timestamp | |
| updated_at | timestamp | |

The combination of `did` and `collection` is unique.
