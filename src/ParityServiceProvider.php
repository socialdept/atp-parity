<?php

namespace SocialDept\AtpParity;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialDept\AtpParity\Blob\BlobDownloader;
use SocialDept\AtpParity\Blob\BlobManager;
use SocialDept\AtpParity\Blob\BlobUploader;
use SocialDept\AtpParity\Commands\DiscoverCommand;
use SocialDept\AtpParity\Commands\ExportCommand;
use SocialDept\AtpParity\Commands\ImportCommand;
use SocialDept\AtpParity\Commands\ImportStatusCommand;
use SocialDept\AtpParity\Commands\MakeMapperCommand;
use SocialDept\AtpParity\Contracts\BlobStorage;
use SocialDept\AtpParity\Contracts\PendingSyncStore;
use SocialDept\AtpParity\Discovery\DiscoveryService;
use SocialDept\AtpParity\Export\ExportService;
use SocialDept\AtpParity\Import\ImportService;
use SocialDept\AtpParity\Listeners\RetryPendingSyncsOnAuth;
use SocialDept\AtpParity\PendingSync\CachePendingSyncStore;
use SocialDept\AtpParity\PendingSync\DatabasePendingSyncStore;
use SocialDept\AtpParity\PendingSync\PendingSyncManager;
use SocialDept\AtpParity\Storage\FilesystemBlobStorage;
use SocialDept\AtpParity\Support\RecordHelper;
use SocialDept\AtpParity\Sync\ReferenceSyncService;
use SocialDept\AtpParity\Sync\SyncService;

class ParityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerBlueprintMacros();
        $this->registerConfiguredMappers();
        $this->registerPendingSyncListener();

        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    /**
     * Register auto-retry listener for pending syncs.
     */
    protected function registerPendingSyncListener(): void
    {
        if (! config('parity.pending_syncs.enabled', false)) {
            return;
        }

        if (! config('parity.pending_syncs.auto_retry', false)) {
            return;
        }

        // Only register if atp-client's SessionAuthenticated event exists
        if (class_exists(\SocialDept\AtpClient\Events\SessionAuthenticated::class)) {
            Event::listen(
                \SocialDept\AtpClient\Events\SessionAuthenticated::class,
                RetryPendingSyncsOnAuth::class
            );
        }
    }

    /**
     * Register Blueprint macros for ATP columns.
     */
    protected function registerBlueprintMacros(): void
    {
        /**
         * Add AT Protocol record columns.
         *
         * Adds: atp_uri, atp_cid, atp_synced_at
         *
         * @return void
         */
        Blueprint::macro('atp', function () {
            /** @var Blueprint $this */
            $uriColumn = config('parity.columns.uri', 'atp_uri');
            $cidColumn = config('parity.columns.cid', 'atp_cid');
            $syncedAtColumn = config('parity.columns.synced_at', 'atp_synced_at');

            $this->string($uriColumn)->nullable()->unique();
            $this->string($cidColumn)->nullable();
            $this->timestamp($syncedAtColumn)->nullable();
        });

        /**
         * Add AT Protocol reference record columns.
         *
         * Adds: atp_reference_uri, and optionally atp_reference_cid
         *
         * @param  bool  $includeCid  Whether to include the CID column (default: true)
         * @return void
         */
        Blueprint::macro('atpReference', function (bool $includeCid = true) {
            /** @var Blueprint $this */
            $uriColumn = config('parity.references.columns.reference_uri', 'atp_reference_uri');
            $cidColumn = config('parity.references.columns.reference_cid', 'atp_reference_cid');

            $this->string($uriColumn)->nullable()->unique();

            if ($includeCid) {
                $this->string($cidColumn)->nullable();
            }
        });

        /**
         * Drop AT Protocol record columns.
         *
         * Drops the unique index first to handle SQLite limitations.
         *
         * @return void
         */
        Blueprint::macro('dropAtp', function () {
            /** @var Blueprint $this */
            $uriColumn = config('parity.columns.uri', 'atp_uri');
            $cidColumn = config('parity.columns.cid', 'atp_cid');
            $syncedAtColumn = config('parity.columns.synced_at', 'atp_synced_at');

            // Drop unique index first (required for SQLite)
            $this->dropUnique([$uriColumn]);
            $this->dropColumn([$uriColumn, $cidColumn, $syncedAtColumn]);
        });

        /**
         * Drop AT Protocol reference record columns.
         *
         * Drops the unique index first to handle SQLite limitations.
         *
         * @param  bool  $includeCid  Whether the CID column exists (default: true)
         * @return void
         */
        Blueprint::macro('dropAtpReference', function (bool $includeCid = true) {
            /** @var Blueprint $this */
            $uriColumn = config('parity.references.columns.reference_uri', 'atp_reference_uri');
            $cidColumn = config('parity.references.columns.reference_cid', 'atp_reference_cid');

            // Drop unique index first (required for SQLite)
            $this->dropUnique([$uriColumn]);

            $columns = [$uriColumn];

            if ($includeCid) {
                $columns[] = $cidColumn;
            }

            $this->dropColumn($columns);
        });
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/parity.php', 'parity');

        $this->app->singleton(MapperRegistry::class);
        $this->app->alias(MapperRegistry::class, 'parity');

        $this->app->singleton(RecordHelper::class, function ($app) {
            return new RecordHelper($app->make(MapperRegistry::class));
        });

        $this->app->singleton(ImportService::class, function ($app) {
            return new ImportService($app->make(MapperRegistry::class));
        });

        $this->app->singleton(SyncService::class, function ($app) {
            return new SyncService($app->make(MapperRegistry::class));
        });

        $this->app->singleton(ReferenceSyncService::class, function ($app) {
            return new ReferenceSyncService(
                $app->make(MapperRegistry::class),
                $app->make(SyncService::class)
            );
        });

        $this->app->singleton(DiscoveryService::class, function ($app) {
            return new DiscoveryService($app->make(ImportService::class));
        });

        $this->app->singleton(ExportService::class, function ($app) {
            return new ExportService(
                $app->make(MapperRegistry::class),
                $app->make(ImportService::class)
            );
        });

        $this->registerBlobServices();
        $this->registerPendingSyncServices();
    }

    /**
     * Register pending sync services.
     */
    protected function registerPendingSyncServices(): void
    {
        $this->app->singleton(PendingSyncStore::class, function ($app) {
            $storage = config('parity.pending_syncs.storage', 'cache');

            if ($storage === 'database') {
                return new DatabasePendingSyncStore;
            }

            $cacheStore = config('parity.pending_syncs.cache_store');
            $cache = $cacheStore
                ? $app->make('cache')->store($cacheStore)
                : $app->make('cache');

            return new CachePendingSyncStore(
                $cache,
                config('parity.pending_syncs.ttl', 3600)
            );
        });

        $this->app->singleton(PendingSyncManager::class, function ($app) {
            return new PendingSyncManager(
                $app->make(PendingSyncStore::class),
                $app->make(SyncService::class),
                $app->make(ReferenceSyncService::class),
                $app->make(MapperRegistry::class)
            );
        });
    }

    /**
     * Register blob-related services.
     */
    protected function registerBlobServices(): void
    {
        $this->app->singleton(BlobStorage::class, function () {
            return new FilesystemBlobStorage(
                config('parity.blobs.disk'),
                config('parity.blobs.path', 'atp-blobs')
            );
        });

        $this->app->singleton(BlobDownloader::class, function ($app) {
            return new BlobDownloader($app->make(BlobStorage::class));
        });

        $this->app->singleton(BlobUploader::class, function ($app) {
            return new BlobUploader($app->make(BlobStorage::class));
        });

        $this->app->singleton(BlobManager::class, function ($app) {
            return new BlobManager(
                $app->make(BlobStorage::class),
                $app->make(BlobDownloader::class),
                $app->make(BlobUploader::class)
            );
        });
    }

    /**
     * Register mappers defined in config.
     */
    protected function registerConfiguredMappers(): void
    {
        $registry = $this->app->make(MapperRegistry::class);

        foreach (config('parity.mappers', []) as $mapperClass) {
            if (class_exists($mapperClass)) {
                $registry->register($this->app->make($mapperClass));
            }
        }
    }

    protected function bootForConsole(): void
    {
        $this->publishes([
            __DIR__.'/../config/parity.php' => config_path('parity.php'),
        ], 'parity-config');

        $this->publishMigrations();

        $this->publishes([
            __DIR__.'/../stubs/mapper.stub' => base_path('stubs/atp-mapper.stub'),
        ], 'parity-stubs');

        $this->commands([
            DiscoverCommand::class,
            ExportCommand::class,
            ImportCommand::class,
            ImportStatusCommand::class,
            MakeMapperCommand::class,
        ]);
    }

    /**
     * Publish migrations with separate tags for optional features.
     */
    protected function publishMigrations(): void
    {
        $migrationPath = __DIR__.'/../database/migrations';
        $date = date('Y_m_d');

        // Core migration (import states) - always needed
        $this->publishesMigrations([
            $migrationPath.'/create_parity_import_states_table.php' => database_path("migrations/{$date}_000001_create_parity_import_states_table.php"),
        ], 'parity-migrations');

        // Optional: Manual conflict resolution (strategy = 'manual')
        $this->publishesMigrations([
            $migrationPath.'/create_parity_conflicts_table.php' => database_path("migrations/{$date}_000002_create_parity_conflicts_table.php"),
        ], 'parity-migrations-conflicts');

        // Optional: Filesystem blob storage (storage_driver = 'filesystem')
        $this->publishesMigrations([
            $migrationPath.'/create_parity_blob_mappings_table.php' => database_path("migrations/{$date}_000003_create_parity_blob_mappings_table.php"),
        ], 'parity-migrations-blobs');

        // Optional: Database pending sync storage (storage = 'database')
        $this->publishesMigrations([
            $migrationPath.'/create_parity_pending_syncs_table.php' => database_path("migrations/{$date}_000004_create_parity_pending_syncs_table.php"),
        ], 'parity-migrations-pending-syncs');
    }

    public function provides(): array
    {
        return [
            'parity',
            MapperRegistry::class,
            RecordHelper::class,
            ImportService::class,
            SyncService::class,
            ReferenceSyncService::class,
            DiscoveryService::class,
            ExportService::class,
            BlobStorage::class,
            BlobDownloader::class,
            BlobUploader::class,
            BlobManager::class,
            PendingSyncStore::class,
            PendingSyncManager::class,
        ];
    }
}
