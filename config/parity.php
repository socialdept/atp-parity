<?php

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
];
