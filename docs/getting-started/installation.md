# Installation

Part of the [atp-parity docs](../introduction.md#documentation). Installing the package, its config and the database columns and tables it needs.

## Installing

```bash
composer require socialdept/atp-parity
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=atp-parity-config
```

If you are coming from 0.6 or earlier, read [Upgrading](../../UPGRADING.md) first. A mapper imports nothing until it declares `accepts()`.

## Configuration

The config lives in `config/atp-parity.php`. Every key has a default, so a published file only needs the parts you change. A typical app sets these:

```php
// config/atp-parity.php
return [
    // Registered mappers
    'mappers' => [
        App\AtpMappers\PostMapper::class,
        App\AtpMappers\ProfileMapper::class,
    ],

    // Column names for AT Protocol metadata
    'columns' => [
        'uri' => 'atp_uri',
        'cid' => 'atp_cid',
    ],

    // Two different questions, neither falling back to the other. Without a lookup
    // the policy that needs it accepts nothing.
    'acceptance' => [
        // Do we hold credentials for this repo, so an inbound record may be our own?
        'is_connected_actor' => fn (string $did) => \App\Models\LoginMethod::validFor($did)->exists(),

        // Do we know this actor at all, whether or not we can write for them?
        'is_known_actor' => fn (string $did) => \App\Models\User::where('did', $did)->exists(),
    ],

    // Steps that bring older record shapes up to the current one. Each declares its
    // lexicon and position with #[UpcastsFrom].
    'upcasters' => [
        App\AtpUpcasters\SplitPalette::class,
    ],

    'sync' => [
        // Skip a resync when the repo already holds the record byte for byte.
        'skip_unchanged' => env('PARITY_SYNC_SKIP_UNCHANGED', true),
    ],

    // Blob handling configuration
    'blobs' => [
        // Turns a model's attached file into a blob reference. The only part of
        // writing a record allowed to perform I/O, and it runs before construction.
        'resolver' => App\Atp\MediaBlobResolver::class,

        // 'filesystem' (requires migrations) or 'medialibrary' (no extra migrations)
        'storage_driver' => \SocialDept\AtpParity\Enums\BlobStorageDriver::Filesystem,
        'download_on_import' => env('PARITY_BLOB_DOWNLOAD', false),
        'disk' => env('PARITY_BLOB_DISK', 'local'),
        'url_strategy' => \SocialDept\AtpParity\Enums\BlobUrlStrategy::Cdn,
    ],
];
```

The models and lookups above (`LoginMethod`, `User`, `SplitPalette`, `MediaBlobResolver`) stand for your own classes.

The other sections of the file are `import`, `records`, `validation`, `references`, `conflicts`, `backfill`, `deferred_references`, `pending_syncs`, `discovery` and `generators`. Each is described on the page for its feature, and the published file comments every key.

## Database

Add the AT Protocol columns to each synced model's table. The `atp()` Blueprint macro adds `atp_uri` (unique), `atp_cid` and `atp_synced_at`, using the names in `columns`:

```php
Schema::table('posts', function (Blueprint $table) {
    $table->atp();
    $table->json('atp_blobs')->nullable(); // For HasAtpBlobs
});
```

Or add them by hand:

```php
Schema::table('posts', function (Blueprint $table) {
    $table->string('atp_uri')->nullable()->unique();
    $table->string('atp_cid')->nullable();
    $table->timestamp('atp_synced_at')->nullable(); // For SyncsWithAtp
    $table->json('atp_blobs')->nullable();          // For HasAtpBlobs
});
```

`dropAtp()` removes them again. Reference records have `atpReference()` and `dropAtpReference()`.

Publish and run Parity's core migration, which creates the `parity_import_states` table:

```bash
php artisan vendor:publish --tag=parity-migrations
php artisan migrate
```

Optional migrations are published separately based on which features you use:

```bash
# Manual conflict resolution (conflicts.strategy = 'manual')
php artisan vendor:publish --tag=parity-migrations-conflicts

# Filesystem blob storage (blobs.storage_driver = 'filesystem')
php artisan vendor:publish --tag=parity-migrations-blobs

# Database pending sync storage (pending_syncs.storage = 'database')
php artisan vendor:publish --tag=parity-migrations-pending-syncs

# Parking reference records that arrive before their target (deferred_references.enabled)
php artisan vendor:publish --tag=parity-migrations-deferred-references
```

`deferred_references.enabled` defaults to `true`. Without its table, a reference record whose target has not arrived is logged and dropped.
