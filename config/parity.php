<?php

use SocialDept\AtpParity\Enums\BlobStorageDriver;
use SocialDept\AtpParity\Enums\BlobUrlStrategy;

return [
    /*
    |--------------------------------------------------------------------------
    | Record Mappers
    |--------------------------------------------------------------------------
    |
    | List of RecordMapper classes to automatically register. Each mapper
    | handles bidirectional conversion between an AT Protocol record DTO
    | and an Eloquent model.
    |
    */
    'mappers' => [
        // App\AtpMappers\PostMapper::class,
        // App\AtpMappers\ProfileMapper::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | AT Protocol Metadata Columns
    |--------------------------------------------------------------------------
    |
    | The column names used to store AT Protocol metadata on models.
    |
    */
    'columns' => [
        'uri' => 'atp_uri',
        'cid' => 'atp_cid',
    ],

    /*
    |--------------------------------------------------------------------------
    | Import Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for importing historical AT Protocol records to your database.
    |
    */
    'import' => [
        // Records per page when listing from PDS
        'page_size' => 100,

        // Delay between pages in milliseconds (rate limiting)
        'page_delay' => 100,

        // Queue name for import jobs
        'queue' => 'default',

        // Database table for storing import state
        'state_table' => 'parity_import_states',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Filtering
    |--------------------------------------------------------------------------
    |
    | Control which firehose events get synced to your database.
    |
    */
    'sync' => [
        // Only sync records from these DIDs (null = all DIDs)
        'dids' => null,

        // Only sync these operations: 'create', 'update', 'delete' (null = all)
        'operations' => null,

        // Custom filter callback: function(SignalEvent $event): bool
        // Return true to sync the event, false to skip it
        'filter' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Conflict Resolution
    |--------------------------------------------------------------------------
    |
    | Strategy for handling conflicts between local and remote changes.
    |
    */
    'conflicts' => [
        // Strategy: 'remote', 'local', 'newest', 'manual'
        'strategy' => env('PARITY_CONFLICT_STRATEGY', 'remote'),

        // Database table for pending conflicts (manual resolution)
        'table' => 'parity_conflicts',

        // Notifiable class or callback for conflict notifications
        'notify' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Collection Discovery
    |--------------------------------------------------------------------------
    |
    | Settings for discovering users with records in specific collections.
    |
    */
    'discovery' => [
        // Relay URL for discovery queries
        'relay' => env('ATP_RELAY_URL', 'https://bsky.network'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Blob Handling
    |--------------------------------------------------------------------------
    |
    | Settings for downloading and uploading AT Protocol blobs.
    |
    */
    'blobs' => [
        // Storage driver: 'filesystem' or 'medialibrary'
        // - filesystem: Uses Laravel filesystem + parity_blob_mappings table
        // - medialibrary: Uses Spatie MediaLibrary (no extra migrations needed)
        'storage_driver' => BlobStorageDriver::tryFrom(
            env('PARITY_BLOB_STORAGE', 'filesystem')
        ) ?? BlobStorageDriver::Filesystem,

        // Automatically download blobs when importing records
        'download_on_import' => env('PARITY_BLOB_DOWNLOAD', false),

        // Laravel filesystem disk for storing blobs (filesystem driver only)
        'disk' => env('PARITY_BLOB_DISK', 'local'),

        // Base path within the disk (filesystem driver only)
        'path' => 'atp-blobs',

        // Maximum blob size to download (bytes)
        'max_download_size' => 10 * 1024 * 1024, // 10MB

        // URL generation strategy (used when blobs aren't stored locally)
        'url_strategy' => BlobUrlStrategy::tryFrom(
            env('PARITY_BLOB_URL_STRATEGY', 'cdn')
        ) ?? BlobUrlStrategy::Cdn,

        // CDN base URL (for Bluesky)
        'cdn_url' => 'https://cdn.bsky.app',

        // Database table for blob mappings (filesystem driver only)
        'table' => 'parity_blob_mappings',

        // MediaLibrary collection prefix for ATP blobs (medialibrary driver only)
        'media_collection_prefix' => 'atp_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for publishing records to AT Protocol.
    |
    */
    'publish' => [
        // Validate records against lexicon schemas on the PDS
        // Set to false to allow custom lexicons on PDSes that don't have them
        'validate' => env('PARITY_PUBLISH_VALIDATE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generator Settings
    |--------------------------------------------------------------------------
    |
    | Configure paths for the make:atp-mapper command.
    | Paths are relative to the application base path.
    |
    */
    'generators' => [
        'mapper_path' => 'app/AtpMappers',
    ],
];
